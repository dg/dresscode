<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Analyses;
use DressCode\ConvergenceException;
use DressCode\FileResult;
use DressCode\Rule;
use DressCode\RuleException;
use PhpSyntax\ParseException;
use PhpSyntax\Parser;
use PhpSyntax\Printer;
use PhpSyntax\Style;


/**
 * Processes one file: parse, passes of the rules, print. A pure function of the path and the text.
 * @internal
 */
final class FileProcessor
{
	private const MaxRounds = 5;

	private readonly Parser $parser;


	public function __construct(
		/** @var list<Rule> in configuration order */
		private readonly array $rules,
		private readonly Analyses\Registry $analyses,
		/** @var \Closure(string): list<string> a name in a suppression comment → the rules it stands for */
		private readonly \Closure $resolveNames,
		/** the version the checked code is written for; it has no default, only the configuration knows it */
		private readonly string $phpVersion,
		private readonly Style $style = new Style,
		/** the line ending of the style follows the prevailing one of each file */
		private readonly bool $detectEol = true,
		private readonly int $maxPasses = 10,
		/** a broken rule contract throws instead of warning */
		private readonly bool $strict = false,
		/** violations it knows are neither reported nor fixed */
		private readonly ?Baseline $baseline = null,
		/** @var array<string, true>  rules whose violations only warn */
		private readonly array $warningRules = [],
	) {
		$this->parser = new Parser;
	}


	/** @return list<Rule> */
	public function getRules(): array
	{
		return $this->rules;
	}


	/**
	 * @param ?list<Rule> $rules  a subset of the rules for this file
	 * @throws RuleException|ConvergenceException
	 */
	public function process(string $path, string $code, ?array $rules = null): FileResult
	{
		$style = $this->detectEol ? $this->style->withEol(Style::detectEol($code)) : $this->style;
		$text = $code;
		$first = null;
		$passes = 0;
		$settled = false;

		// a mutated tree is not the tree the parser would build from the printed text, so a rule can
		// miss what another one has just written; the text is what has to settle, not the tree
		for ($round = 0; $round < self::MaxRounds && !$settled; $round++) {
			try {
				$file = $this->parser->parse($text);
			} catch (ParseException $e) {
				return $round === 0
					? new FileResult($path, $code, output: null, error: $e->getMessage(), errorLine: $e->sourceLine)
					: new FileResult($path, $code, output: null, failure: "The fixed code no longer parses: {$e->getMessage()} on line $e->sourceLine.");
			}

			$runner = new PassRunner($rules ?? $this->rules, $this->analyses, $this->resolveNames, $this->maxPasses, $this->strict, $this->baseline, $this->warningRules);
			$result = $runner->run($file, $text, $path, $style, $this->phpVersion);
			$first ??= $result;
			$passes += $result->passes;
			$printed = $result->mutated ? Printer::print($file) : $text;
			$settled = $printed === $text;
			$text = $printed;
		}

		return new FileResult(
			$path,
			$code,
			$text,
			$first->violations,
			[
				...$first->warnings,
				...($settled ? [] : ['The fixes did not settle in ' . self::MaxRounds . ' rounds; run the fixer again.']),
			],
			passes: $passes,
			baselined: $first->baselined,
		);
	}
}
