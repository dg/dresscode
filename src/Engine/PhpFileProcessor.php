<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Analyses, ConfigurationException, ConvergenceException, FileResult, Rule, RuleException, Style, Violation};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use PhpSyntax\{ParseException, Parser, SymbolKind};
use function strlen;


/**
 * Processes one PHP file: parse, passes of the rules, print. A pure function of the path and the text.
 * @internal
 */
final readonly class PhpFileProcessor implements FileProcessor
{
	private const MaxRounds = 5;

	private Parser $parser;
	private RulePlan $plan;


	/** @throws ConfigurationException when the rules do not fit the tree or each other */
	public function __construct(
		/** @var list<Rule> in configuration order */
		public array $rules,
		private Analyses\Registry $analyses,
		/** the version the checked code is written for; it has no default, only the configuration knows it */
		private string $phpVersion,
		private Style $style = new Style,
		/** the line ending of the style follows the prevailing one of each file */
		private bool $detectLineEnding = true,
		private ReportPolicy $policy = new ReportPolicy,
		private ?Profiler $profiler = null,
		/** @var array<class-string, Gate>  the class of a rule => the gate of its reports */
		private array $gates = [],
	) {
		$this->parser = new Parser;
		$this->plan = new RulePlan($this->rules, $this->profiler, $this->gates);
	}


	/**
	 * The decisions the rules report in this run.
	 * @return list<string>
	 */
	public function getReportedDecisions(): array
	{
		$decisions = [];
		foreach ($this->rules as $rule) {
			array_push($decisions, ...$this->plan->gates[$rule::class]->getAdmitted());
		}

		return $decisions;
	}


	/**
	 * @param  array<string, true>  $acceptedRisks  fingerprints of the occurrences whose risky fix is allowed on top of
	 *   what the configuration allows
	 * @throws RuleException|ConvergenceException|ConfigurationException
	 */
	public function process(string $path, string $code, array $acceptedRisks = []): FileResult
	{
		$style = $this->detectLineEnding ? $this->style->withLineEnding(Style::detectLineEnding($code)) : $this->style;
		$text = $code;
		$first = null;
		$passes = 0;
		$seen = [];
		$settled = false;
		$remaining = null;
		$removedImports = $mutatedRules = [];
		$start = $this->profiler ? hrtime(as_number: true) : 0;
		$times = ['parse' => 0, 'passes' => 0];

		// a mutated tree is not the tree the parser would build from the printed text, so a rule can miss what another
		// one has just written; the strict run makes the text settle in rounds, the others take what the last pass
		// of the tree left for what a round over the text would report, which spares a pass; they only parse the printed
		// text, so that a broken rule never writes code PHP refuses
		$passLoop = new PassLoop($this->plan, $this->analyses, $this->policy, $this->profiler);
		$rounds = 0;
		while (!$settled) {
			$rounds++;
			$lap = $this->profiler ? hrtime(as_number: true) : 0;
			try {
				$file = $this->parser->parse($text);
				self::checkImports($file);
			} catch (ParseException $e) {
				return self::createSyntaxError($path, $code, $e, $rounds);
			}

			if ($this->profiler) {
				$times['parse'] += hrtime(as_number: true) - $lap;
				$lap = hrtime(as_number: true);
			}

			try {
				$result = $passLoop->run($file, $text, $path, $style, $this->phpVersion, $acceptedRisks);
			} catch (RuleException $e) {
				// the types refuse code PHP would not compile, whichever rule asks for them first
				$previous = $e->getPrevious();
				return $previous instanceof Analyses\UncompilableCodeException
					? self::createSyntaxError($path, $code, new ParseException($previous->getMessage()), $rounds)
					: throw $e;
			}

			$first ??= $result;
			$passes += $result->passes;
			array_push($removedImports, ...$result->removedImports);
			array_push($mutatedRules, ...$result->mutatedRules);
			if ($this->profiler) {
				$times['passes'] += hrtime(as_number: true) - $lap;
			}

			$printed = $result->output ?? $text;
			$settled = $printed === $text;
			$seen[hash('xxh3', $text)] = true;
			// a text seen before is a cycle, and one still changing in the last round is a broken rule too
			if (!$settled && ($rounds === self::MaxRounds || isset($seen[hash('xxh3', $printed)]))) {
				throw new ConvergenceException($path, $result->mutatedRules, Diff::unified($text, $printed, $path));
			}

			$text = $printed;
			if (!$settled && !$this->policy->strict && $result->remaining !== null) {
				$lap = $this->profiler ? hrtime(as_number: true) : 0;
				try {
					self::checkImports($this->parser->parse($text));
				} catch (ParseException $e) {
					return self::createParseFailure($path, $code, $e);
				}

				if ($this->profiler) {
					$times['parse'] += hrtime(as_number: true) - $lap;
				}

				$remaining = $result->remaining;
				$rounds++; // the parse of the printed text stands for the round over it
				break;
			}
		}

		if ($this->profiler) {
			foreach ($times as $phase => $time) {
				$this->profiler->addPhase($phase, $time, $rounds);
			}

			$this->profiler->addFile([
				'path' => $path,
				'size' => strlen($code),
				'time' => hrtime(as_number: true) - $start,
				...$times,
				'rounds' => $rounds,
				'passCount' => $passes,
			]);
		}

		// the last round is a check of the text the fix writes; a round after the first parsed that text, not the file as read
		$remaining ??= $result === $first
			? $result->violations
			: array_map(fn(Violation $v) => new Violation(...[...get_object_vars($v), 'originalLines' => null]), $result->violations);
		return new FileResult(
			$path,
			$code,
			$text,
			$first->violations,
			$first->warnings,
			passes: $passes,
			baselined: $first->baselined,
			remaining: $remaining,
			removedImports: $removedImports,
			mutatedRules: array_values(array_unique($mutatedRules)),
		);
	}


	/** A syntax error of the file in the first round, a failure of the fixer in a later one, over the text it wrote. */
	private static function createSyntaxError(string $path, string $code, ParseException $e, int $round): FileResult
	{
		return $round === 1
			? new FileResult($path, $code, output: null, syntaxError: $e->getMessage(), syntaxErrorLine: $e->sourceLine)
			: self::createParseFailure($path, $code, $e);
	}


	/**
	 * Refuses two imports of one kind under one name in a scope, the name of a constant in its letter case and the others
	 * in any, which PHP refuses to compile as the parser of PhpSyntax does not.
	 * @throws ParseException
	 */
	private static function checkImports(FileNode|NamespaceNode $scope): void
	{
		$names = [];
		foreach ($scope->statements->getItems() as $statement) {
			if ($statement instanceof NamespaceNode) {
				self::checkImports($statement);
				continue;
			} elseif (!$statement instanceof UseNode) {
				continue;
			}

			foreach ($statement->items->getItems() as $item) {
				$name = $item->alias->text ?? $item->name->shortName;
				$key = $item->symbolKind->name . ($item->symbolKind === SymbolKind::Constant ? $name : strtolower($name));
				if (isset($names[$key])) {
					throw new ParseException(
						"Cannot use $item->fullName as $name because the name is already in use",
						$item->getFirstToken()->line,
					);
				}

				$names[$key] = true;
			}
		}
	}


	private static function createParseFailure(string $path, string $code, ParseException $e): FileResult
	{
		return new FileResult(
			$path,
			$code,
			output: null,
			failure: "The fixed code no longer parses: {$e->getMessage()} on line $e->sourceLine.",
		);
	}
}
