<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Analyses, ConfigurationException, ConvergenceException, FileResult, Rule, RuleException, Style};
use PhpSyntax\{ParseException, Parser};
use function strlen;


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
	 * @param  array<string, true>  $acceptedRisks  fingerprints of the occurrences whose risky fix is allowed on top of what the configuration allows
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
		$start = $this->profiler ? hrtime(true) : 0;
		$times = ['parse' => 0, 'passes' => 0];

		// a mutated tree is not the tree the parser would build from the printed text, so a rule can miss what another
		// one has just written; the strict run makes the text settle in rounds, the others take what the last pass
		// of the tree left for what a round over the text would report, which spares a pass; they only parse the printed
		// text, so that a broken rule never writes code PHP refuses
		$runner = null;
		$rounds = 0;
		for ($round = 1; !$settled; $round++) {
			$rounds = $round;
			$lap = $this->profiler ? hrtime(true) : 0;
			try {
				$file = $this->parser->parse($text);
			} catch (ParseException $e) {
				return $round === 1
					? new FileResult($path, $code, output: null, syntaxError: $e->getMessage(), syntaxErrorLine: $e->sourceLine)
					: self::createParseFailure($path, $code, $e);
			}

			if ($this->profiler) {
				$times['parse'] += hrtime(true) - $lap;
				$lap = hrtime(true);
			}

			$runner ??= new PassLoop($this->plan, $this->analyses, $this->policy, $this->profiler);
			$result = $runner->run($file, $text, $path, $style, $this->phpVersion, $acceptedRisks);
			$first ??= $result;
			$passes += $result->passes;
			if ($this->profiler) {
				$times['passes'] += hrtime(true) - $lap;
			}

			$printed = $result->output ?? $text;
			$settled = $printed === $text;
			$seen[hash('xxh3', $text)] = true;
			// a text seen before is a cycle, and one still changing in the last round is a broken rule too
			if (!$settled && ($round === self::MaxRounds || isset($seen[hash('xxh3', $printed)]))) {
				throw new ConvergenceException($path, $result->mutatedRules, Diff::unified($text, $printed, $path));
			}

			$text = $printed;
			if (!$settled && !$this->policy->strict && $result->remaining !== null) {
				$lap = $this->profiler ? hrtime(true) : 0;
				try {
					$this->parser->parse($text);
				} catch (ParseException $e) {
					return self::createParseFailure($path, $code, $e);
				}

				if ($this->profiler) {
					$times['parse'] += hrtime(true) - $lap;
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
				'time' => hrtime(true) - $start,
				...$times,
				'rounds' => $rounds,
				'passCount' => $passes,
			]);
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
