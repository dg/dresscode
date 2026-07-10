<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\AnalysisRegistry;
use DressCode\ConvergenceException;
use DressCode\Rule;
use DressCode\RuleException;
use PhpSyntax\ParseException;
use PhpSyntax\Parser\Parser;
use PhpSyntax\PhpVersion;
use PhpSyntax\Printer;
use PhpSyntax\Style;


/**
 * Processes one file: parse, passes of the rules, print. A pure function of the path and the text.
 * @internal
 */
final class FileProcessor
{
	private const MaxRounds = 5;

	private readonly PhpVersion $phpVersion;
	private readonly Parser $parser;


	/**
	 * @param list<Rule> $rules  in configuration order
	 * @param \Closure(string): ?string $resolveAlias  rule name or alias → canonical name
	 * @param bool $detectEol  the line ending of the style follows the prevailing one of each file
	 * @param bool $strict  a broken rule contract throws instead of warning
	 */
	public function __construct(
		private readonly array $rules,
		private readonly AnalysisRegistry $analyses,
		private readonly \Closure $resolveAlias,
		private readonly Style $style = new Style,
		private readonly bool $detectEol = true,
		?PhpVersion $phpVersion = null,
		private readonly int $maxPasses = 10,
		private readonly bool $strict = false,
	) {
		$this->phpVersion = $phpVersion ?? PhpVersion::current();
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
					? new FileResult($path, $code, output: null, error: $e->getMessage(), errorLine: $e->originalLine)
					: new FileResult($path, $code, output: null, failure: "The fixed code no longer parses: {$e->getMessage()} on line $e->originalLine.");
			}

			$runner = new PassRunner($rules ?? $this->rules, $this->analyses, $this->resolveAlias, $this->maxPasses, $this->strict);
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
		);
	}
}
