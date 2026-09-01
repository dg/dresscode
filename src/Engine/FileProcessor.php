<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Analyses, ConfigurationException, ConvergenceException, FileResult, Rule, RuleException, Style};
use PhpSyntax\{ParseException, Parser, Printer};


/**
 * Processes one file: parse, passes of the rules, print. A pure function of the path and the text.
 * @internal
 */
final readonly class FileProcessor
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
		/** @var array<class-string, Gate>  the class of a rule => the gate of its reports */
		private array $gates = [],
	) {
		$this->parser = new Parser;
		$this->plan = new RulePlan($this->rules, $this->gates);
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


	/** @throws RuleException|ConvergenceException|ConfigurationException */
	public function process(string $path, string $code): FileResult
	{
		$style = $this->detectLineEnding ? $this->style->withLineEnding(Style::detectLineEnding($code)) : $this->style;
		$text = $code;
		$first = null;
		$passes = 0;
		$seen = [];
		$settled = false;
		$remaining = null;

		// a mutated tree is not the tree the parser would build from the printed text, so a rule can miss what another
		// one has just written; the strict run makes the text settle in rounds, the others take what the last pass
		// of the tree left for what a round over the text would report, which spares a pass; they only parse the printed
		// text, so that a broken rule never writes code PHP refuses
		$runner = null;
		for ($round = 1; !$settled; $round++) {
			try {
				$file = $this->parser->parse($text);
			} catch (ParseException $e) {
				return $round === 1
					? new FileResult($path, $code, output: null, syntaxError: $e->getMessage(), syntaxErrorLine: $e->sourceLine)
					: self::createParseFailure($path, $code, $e);
			}

			$runner ??= new PassLoop($this->plan, $this->analyses, $this->policy);
			$result = $runner->run($file, $text, $path, $style, $this->phpVersion);
			$first ??= $result;
			$passes += $result->passes;
			$printed = $result->mutated ? Printer::print($file) : $text;
			$settled = $printed === $text;
			$seen[hash('xxh3', $text)] = true;
			// a text seen before is a cycle, and one still changing in the last round is a broken rule too
			if (!$settled && ($round === self::MaxRounds || isset($seen[hash('xxh3', $printed)]))) {
				throw new ConvergenceException($path, $result->mutatedRules, Diff::unified($text, $printed, $path));
			}

			$text = $printed;
			if (!$settled && !$this->policy->strict && $result->remaining !== null) {
				try {
					$this->parser->parse($text);
				} catch (ParseException $e) {
					return self::createParseFailure($path, $code, $e);
				}

				$remaining = $result->remaining;
				break;
			}
		}

		return new FileResult(
			$path,
			$code,
			$text,
			$first->violations,
			$first->warnings,
			passes: $passes,
			baselined: $first->baselined,
			remaining: $remaining ?? $result->violations, // the last round is a check of the text the fix writes
		);
	}


	private static function createParseFailure(string $path, string $code, ParseException $e): FileResult
	{
		return new FileResult($path, $code, output: null, failure: "The fixed code no longer parses: {$e->getMessage()} on line $e->sourceLine.");
	}
}
