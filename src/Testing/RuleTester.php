<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Testing;

use DressCode\{Analyses, Config, ConvergenceException, Profile, Rule, RuleException, RuleInfo, Style, Violation};
use DressCode\Config\PresetResolver;
use DressCode\Engine\{Diff, PassResult, PassRunner};
use PhpSyntax\Analyses\NamespacedSymbols;
use PhpSyntax\Lexer\Lexer;
use PhpSyntax\{Node, ParseException, Parser, Printer};
use PhpSyntax\Nodes\FileNode;
use function count;


/**
 * Tests a rule over fixtures: the output must be the expected one and the rule must keep its contract
 * (idempotence, suppression, comments preserved, the parent invariant, no silent mutation, no risky violation
 * left where its fix was allowed).
 * Works from any test framework; a failure is a TestFailure exception.
 */
final class RuleTester
{
	/** the widest line a fixture is checked with unless its header says another */
	private const DefaultLineLength = 120;


	/**
	 * Runs every *.code fixture in the directory: the output must equal <name>.expected (the input when
	 * there is none), the violations <name>.violations when present. A fixture sets what its run is given in the
	 * comments that open it: the options of the rule `// {"option": value}`, the version of PHP it is
	 * written for `// php 8.4`, the widest line `// lineLength 80` (120 without it), that the run allows a fix that
	 * changes what the code does `// risky`, and what the namespaces declare outside it `// namespacedFunctions App\helper, App\Utils\{format}`,
	 * `// namespacedConstants App\LIMIT` and `// nameResolution certain`. Returns the count.
	 * @param class-string<Rule>|\Closure(array<string, mixed>): Rule $rule
	 * @throws TestFailure
	 */
	public static function run(string|\Closure $rule, string $dir, ?string $phpVersion = null): int
	{
		$files = glob(rtrim($dir, '/\\') . '/*.code') ?: [];
		if (!$files) {
			throw new TestFailure("No *.code fixtures in $dir.");
		}

		foreach ($files as $file) {
			self::runFixture($rule, $file, $phpVersion);
		}

		return count($files);
	}


	/**
	 * @param class-string<Rule>|\Closure(array<string, mixed>): Rule $rule
	 * @throws TestFailure
	 */
	public static function runFixture(string|\Closure $rule, string $file, ?string $phpVersion = null): void
	{
		$code = self::read($file);
		$base = (string) preg_replace('~\.code$~', '', $file);
		$expected = is_file("$base.expected") ? self::read("$base.expected") : null;
		$violations = is_file("$base.violations")
			? preg_split('~\r?\n~', trim(self::read("$base.violations")), -1, PREG_SPLIT_NO_EMPTY)
			: null;
		$options = self::readOptions($code, $file);
		$instance = $rule instanceof \Closure ? $rule($options) : PresetResolver::createRule($rule, $options ?: true);
		try {
			self::check(
				$instance,
				$code,
				$expected,
				$violations,
				$phpVersion ?? self::readPhpVersion($code, $file),
				basename($file),
				self::readRisky($code),
				self::readNamespacedSymbols($code, $file),
				self::readLineLength($code, $file),
			);
		} catch (TestFailure $e) {
			throw new TestFailure("$file: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * What the rule reports over the fixture, line by line as the .violations file records it; for a tool
	 * that writes such a file.
	 * @param class-string<Rule>|\Closure(array<string, mixed>): Rule $rule
	 * @return list<string>
	 * @throws TestFailure
	 */
	public static function collectViolations(string|\Closure $rule, string $file, ?string $phpVersion = null): array
	{
		[, $result] = self::processFixture($rule, $file, $phpVersion);
		return array_map(fn(Violation $v) => "$v->line: $v->message", $result->violations);
	}


	/**
	 * What the rule makes of the fixture; for a tool that writes the .expected file.
	 * @param class-string<Rule>|\Closure(array<string, mixed>): Rule $rule
	 * @throws TestFailure
	 */
	public static function collectOutput(string|\Closure $rule, string $file, ?string $phpVersion = null): string
	{
		[$node] = self::processFixture($rule, $file, $phpVersion);
		return Printer::print($node);
	}


	/**
	 * @param ?string $expected  the output; null when the rule must leave the code as it is
	 * @param ?list<string> $violations  "line: message" each; null to skip the check
	 * @param NamespacedSymbols $namespacedSymbols  what the namespaces declare outside the code
	 * @throws TestFailure
	 */
	public static function check(
		Rule $rule,
		string $code,
		?string $expected = null,
		?array $violations = null,
		?string $phpVersion = null,
		string $name = 'code',
		bool $fixRisky = false,
		NamespacedSymbols $namespacedSymbols = new NamespacedSymbols,
		int $lineLength = self::DefaultLineLength,
	): void
	{
		$expected ??= $code;
		$phpVersion ??= self::defaultPhpVersion($rule);
		[$file, $result] = self::process($rule, $code, $phpVersion, $name, $fixRisky, $namespacedSymbols, $lineLength);
		self::checkParents($file);
		$output = Printer::print($file);
		if ($output !== $expected) {
			throw new TestFailure("The output differs from the expected one:\n" . Diff::unified($expected, $output, $name));
		}

		if ($violations !== null) {
			$actual = array_map(fn(Violation $v) => "$v->line: $v->message", $result->violations);
			if ($actual !== $violations) {
				throw new TestFailure(
					"The violations differ from the expected ones:\n"
					. Diff::unified(implode("\n", $violations) . "\n", implode("\n", $actual) . "\n", "$name.violations"),
				);
			}
		}

		if (!RuleInfo::of($rule)->modifiesComments) {
			self::checkComments($code, $output);
		}

		[, $again] = self::process($rule, $output, $phpVersion, $name, $fixRisky, $namespacedSymbols, $lineLength);
		if ($again->mutated) {
			throw new TestFailure(
				'The rule is not idempotent: it fixes its own output again'
				. ($again->violations ? ' (' . implode(', ', array_map(fn(Violation $v) => "$v->line: $v->message", $again->violations)) . ')' : '')
				. '.',
			);
		}

		$left = $fixRisky ? array_filter($again->violations, fn(Violation $v) => $v->risky) : [];
		if ($left) {
			throw new TestFailure(
				'The rule leaves a risky violation although the run allowed its fix ('
				. implode(', ', array_map(fn(Violation $v) => "$v->line: $v->message", $left))
				. '); a report the rule has no fix for says fixable: false.',
			);
		}

		if ($result->violations && preg_match('~<\?php\b~i', $code)) {
			$ignored = (string) preg_replace('~<\?php(\s)~i', '<?php /* dresscode:ignore-file */$1', $code, 1);
			[$ignoredFile, $ignoredResult] = self::process($rule, $ignored, $phpVersion, $name, $fixRisky, $namespacedSymbols, $lineLength);
			if ($ignoredResult->violations || Printer::print($ignoredFile) !== $ignored) {
				throw new TestFailure('The rule ignores the dresscode:ignore-file comment: it still reports or changes the file.');
			}
		}
	}


	/**
	 * The fixture processed with what its header says.
	 * @param class-string<Rule>|\Closure(array<string, mixed>): Rule $rule
	 * @return array{FileNode, PassResult}
	 * @throws TestFailure
	 */
	private static function processFixture(string|\Closure $rule, string $file, ?string $phpVersion): array
	{
		$code = self::read($file);
		$options = self::readOptions($code, $file);
		$instance = $rule instanceof \Closure ? $rule($options) : PresetResolver::createRule($rule, $options ?: true);
		return self::process(
			$instance,
			$code,
			$phpVersion ?? self::readPhpVersion($code, $file) ?? self::defaultPhpVersion($instance),
			basename($file),
			self::readRisky($code),
			self::readNamespacedSymbols($code, $file),
			self::readLineLength($code, $file),
		);
	}


	/**
	 * @return array{FileNode, PassResult}
	 * @throws TestFailure
	 */
	private static function process(
		Rule $rule,
		string $code,
		string $phpVersion,
		string $name,
		bool $fixRisky,
		NamespacedSymbols $namespacedSymbols,
		int $lineLength,
	): array
	{
		try {
			$file = (new Parser)->parse($code);
		} catch (ParseException $e) {
			throw new TestFailure("The code does not parse: {$e->getMessage()}");
		}

		$runner = new PassRunner([$rule], new Analyses\Registry($namespacedSymbols), fn(string $rule) => [$rule], strict: true, fixRisky: $fixRisky);
		try {
			$result = $runner->run($file, $code, $name, new Style(eol: Style::detectEol($code), lineLength: $lineLength), $phpVersion);
		} catch (RuleException $e) {
			throw new TestFailure($e->getMessage(), previous: $e);
		} catch (ConvergenceException $e) {
			throw new TestFailure($e->getMessage() . ($e->diff === '' ? '' : "\n$e->diff"), previous: $e);
		}

		return [$file, $result];
	}


	/** @throws TestFailure */
	private static function checkParents(Node $node): void
	{
		foreach ($node->getChildren() as $child) {
			if ($child->parent !== $node) {
				throw new TestFailure('The parent invariant is broken: ' . $child::class . ' under ' . $node::class . ' has another parent.');
			}

			if ($child instanceof Node) {
				self::checkParents($child);
			}
		}
	}


	/** @throws TestFailure */
	private static function checkComments(string $before, string $after): void
	{
		$lexer = new Lexer;
		$collect = function (string $code) use ($lexer): array {
			$comments = [];
			foreach ($lexer->tokenize($code, withPositions: false) as $token) {
				foreach ([...$token->leadingTrivia, ...$token->trailingTrivia] as $trivia) {
					if ($trivia->isComment()) {
						$comments[] = rtrim((string) preg_replace('~\r\n?~', "\n", $trivia->text));
					}
				}
			}

			sort($comments);
			return $comments;
		};
		$lost = array_diff_assoc($collect($before), $collect($after));
		if ($lost) {
			throw new TestFailure('The rule lost or changed comments: ' . implode(', ', array_map(fn($c) => json_encode($c, JSON_UNESCAPED_SLASHES), $lost)) . '.');
		}
	}


	/**
	 * @return array<string, mixed>
	 * @throws TestFailure
	 */
	private static function readOptions(string $code, string $file): array
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*(\{.*\})\s*$~', $line, $m)) {
				try {
					return json_decode($m[1], associative: true, flags: JSON_THROW_ON_ERROR);
				} catch (\JsonException $e) {
					throw new TestFailure("$file: invalid options header: {$e->getMessage()}");
				}
			}
		}

		return [];
	}


	/** `// risky` in the header lets the rule make the fixes that change what the code does. */
	private static function readRisky(string $code): bool
	{
		return array_any(self::readHeader($code), fn(string $line) => preg_match('~^//\s*risky\s*$~i', $line) === 1);
	}


	/** The version the fixture is written for, when it says so; `// php 8.4`. */
	private static function readPhpVersion(string $code, string $file): ?string
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*php\s+(\S+)\s*$~i', $line, $m)) {
				return preg_match('~^\d+\.\d+$~D', $m[1])
					? $m[1]
					: throw new TestFailure("$file: Invalid header 'php $m[1]'.");
			}
		}

		return null;
	}


	/** The widest line the fixture is checked with, when it says so; `// lineLength 80`. */
	private static function readLineLength(string $code, string $file): int
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*lineLength\s+(\S+)\s*$~i', $line, $m)) {
				return preg_match('~^[1-9]\d*$~D', $m[1])
					? (int) $m[1]
					: throw new TestFailure("$file: Invalid header 'lineLength $m[1]'.");
			}
		}

		return self::DefaultLineLength;
	}


	/**
	 * What the namespaces declare outside the fixture, the names listed the way a use statement lists them; without
	 * a header nothing is listed and nothing is known.
	 * @throws TestFailure
	 */
	private static function readNamespacedSymbols(string $code, string $file): NamespacedSymbols
	{
		$functions = $constants = [];
		$resolution = null;
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*namespacedFunctions\s+(.+?)\s*$~', $line, $m)) {
				$functions = [...$functions, ...self::splitItems($m[1])];
			} elseif (preg_match('~^//\s*namespacedConstants\s+(.+?)\s*$~', $line, $m)) {
				$constants = [...$constants, ...self::splitItems($m[1])];
			} elseif (preg_match('~^//\s*nameResolution\s+(\S+)\s*$~', $line, $m)) {
				$resolution = $m[1];
			}
		}

		try {
			$profile = new Profile(namespaces: ['functions' => $functions, 'constants' => $constants], nameResolution: $resolution);
		} catch (\InvalidArgumentException $e) {
			throw new TestFailure("$file: Invalid header of the namespaces: {$e->getMessage()}");
		}

		return new NamespacedSymbols($profile->namespaces['functions'], $profile->namespaces['constants'], $profile->nameResolution === 'certain');
	}


	/**
	 * The items of a header, split at the commas outside a group, because a use statement takes a group of its own.
	 * @return list<string>
	 */
	private static function splitItems(string $items): array
	{
		return preg_split('~\s*,\s*(?![^{]*\})~', $items) ?: [];
	}


	/**
	 * A fixture says nothing about the version when the rule works everywhere, and then it is tested at the
	 * default target; a rule of a newer construct at the version that construct came with.
	 */
	private static function defaultPhpVersion(Rule $rule): string
	{
		return RuleInfo::of($rule)->getMinPhpVersion() ?? Config::DefaultPhpVersion;
	}


	/**
	 * The lines a fixture puts its headers on: those that open it, blank ones, a hashbang, the line of the open
	 * tag and the // comments, up to the first line of anything else.
	 * @return list<string>
	 */
	private static function readHeader(string $code): array
	{
		$header = [];
		foreach (preg_split('~\r?\n~', $code) ?: [] as $line) {
			if (!preg_match('~^(\s*|//.*|#!.*|.*<\?php\b.*)$~i', $line)) {
				break;
			}

			$header[] = $line;
		}

		return $header;
	}


	/** @throws TestFailure */
	private static function read(string $file): string
	{
		$content = @file_get_contents($file); // @ - reported as exception
		return $content === false ? throw new TestFailure("Cannot read $file.") : $content;
	}
}
