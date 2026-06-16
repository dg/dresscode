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
final class FileProcessor
{
	private const MaxRounds = 5;

	private readonly Parser $parser;

	/** made at the first file, whose run reports a configuration the claims do not fit */
	private ?RulePlan $plan = null;


	public function __construct(
		/** @var list<Rule> in configuration order */
		public readonly array $rules,
		private readonly Analyses\Registry $analyses,
		/** @var \Closure(string): list<string> a name in a suppression comment => the rules it stands for */
		private readonly \Closure $resolveNames,
		/** the version the checked code is written for; it has no default, only the configuration knows it */
		private readonly string $phpVersion,
		private readonly Style $style = new Style,
		/** the line ending of the style follows the prevailing one of each file */
		private readonly bool $detectLineEnding = true,
		private readonly int $maxPasses = 10,
		/** a broken rule contract throws instead of warning */
		private readonly bool $strict = false,
		/** @var array<string, true>  rules whose violations only warn */
		private readonly array $warningRules = [],
		/** whether every fix that may change what the code does is allowed */
		private readonly bool $fixRisky = false,
		/** @var array<string, true>  rules whose fixes that may change what the code does are allowed */
		private readonly array $fixRiskyRules = [],
	) {
		$this->parser = new Parser;
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
		for ($round = 1; !$settled; $round++) {
			try {
				$file = $this->parser->parse($text);
			} catch (ParseException $e) {
				return $round === 1
					? new FileResult($path, $code, output: null, syntaxError: $e->getMessage(), syntaxErrorLine: $e->sourceLine)
					: self::createParseFailure($path, $code, $e);
			}

			$this->plan ??= new RulePlan($this->rules);
			$runner = new PassRunner($this->plan, $this->analyses, $this->resolveNames, $this->maxPasses, $this->strict, $this->warningRules, $this->fixRisky, $this->fixRiskyRules);
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
			if (!$settled && !$this->strict && $result->remaining !== null) {
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
			remaining: $remaining ?? $result->violations, // the last round is a check of the text the fix writes
		);
	}


	private static function createParseFailure(string $path, string $code, ParseException $e): FileResult
	{
		return new FileResult($path, $code, output: null, failure: "The fixed code no longer parses: {$e->getMessage()} on line $e->sourceLine.");
	}
}
