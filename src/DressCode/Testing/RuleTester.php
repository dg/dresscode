<?php declare(strict_types=1);

namespace DressCode\Testing;

use DressCode\AnalysisRegistry;
use DressCode\Config\PresetResolver;
use DressCode\ConvergenceException;
use DressCode\Engine\Diff;
use DressCode\Engine\PassResult;
use DressCode\Engine\PassRunner;
use DressCode\Rule;
use DressCode\RuleException;
use DressCode\RuleInfo;
use DressCode\Violation;
use PhpSyntax\Lexer\Lexer;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\ParseException;
use PhpSyntax\Parser\Parser;
use PhpSyntax\PhpVersion;
use PhpSyntax\Printer;
use PhpSyntax\Style;
use function count;


/**
 * Tests a rule over fixtures: the output must be the expected one and the rule must keep its contract
 * (idempotence, suppression, comments preserved, the parent invariant, no silent mutation).
 * Works from any test framework; a failure is a TestFailure exception.
 */
final class RuleTester
{
	/**
	 * Runs every *.code fixture in the directory: the output must equal <name>.expected (the input when
	 * there is none), the violations <name>.violations when present. A fixture may set the options
	 * of the rule and the version of PHP it is written for in a comment on one of its first three lines:
	 * `// {"option": value}` and `// php 8.4`. Returns the count.
	 * @param class-string<Rule>|\Closure(array<string, mixed>): Rule $rule
	 * @throws TestFailure
	 */
	public static function run(string|\Closure $rule, string $dir, ?PhpVersion $phpVersion = null): int
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
	public static function runFixture(string|\Closure $rule, string $file, ?PhpVersion $phpVersion = null): void
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
			self::check($instance, $code, $expected, $violations, $phpVersion ?? self::readPhpVersion($code, $file), basename($file));
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
	public static function collectViolations(string|\Closure $rule, string $file, ?PhpVersion $phpVersion = null): array
	{
		$code = self::read($file);
		$options = self::readOptions($code, $file);
		$instance = $rule instanceof \Closure ? $rule($options) : PresetResolver::createRule($rule, $options ?: true);
		[, $result] = self::process($instance, $code, $phpVersion ?? self::readPhpVersion($code, $file) ?? self::defaultPhpVersion($instance), basename($file));
		return array_map(fn(Violation $v) => "$v->line: $v->message", $result->violations);
	}


	/**
	 * @param ?string $expected  the output; null when the rule must leave the code as it is
	 * @param ?list<string> $violations  "line: message" each; null to skip the check
	 * @throws TestFailure
	 */
	public static function check(
		Rule $rule,
		string $code,
		?string $expected = null,
		?array $violations = null,
		?PhpVersion $phpVersion = null,
		string $name = 'code',
	): void
	{
		$expected ??= $code;
		$phpVersion ??= self::defaultPhpVersion($rule);
		[$file, $result] = self::process($rule, $code, $phpVersion, $name);
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

		[, $again] = self::process($rule, $output, $phpVersion, $name);
		$fixed = array_filter($again->violations, fn(Violation $v) => $v->fixable);
		if ($again->mutated || $fixed) {
			throw new TestFailure(
				'The rule is not idempotent: it fixes its own output again'
				. ($fixed ? ' (' . implode(', ', array_map(fn(Violation $v) => "$v->line: $v->message", $fixed)) . ')' : '')
				. '.',
			);
		}

		if ($result->violations && preg_match('~<\?php\b~i', $code)) {
			$ignored = (string) preg_replace('~<\?php(\s)~i', '<?php /* dresscode:ignore-file */$1', $code, 1);
			[$ignoredFile, $ignoredResult] = self::process($rule, $ignored, $phpVersion, $name);
			if ($ignoredResult->violations || Printer::print($ignoredFile) !== $ignored) {
				throw new TestFailure('The rule ignores the dresscode:ignore-file comment: it still reports or changes the file.');
			}
		}
	}


	/**
	 * @return array{FileNode, PassResult}
	 * @throws TestFailure
	 */
	private static function process(Rule $rule, string $code, PhpVersion $phpVersion, string $name): array
	{
		try {
			$file = (new Parser)->parse($code);
		} catch (ParseException $e) {
			throw new TestFailure("The code does not parse: {$e->getMessage()}");
		}

		$runner = new PassRunner([$rule], new AnalysisRegistry, fn(string $rule) => [$rule], strict: true);
		try {
			$result = $runner->run($file, $code, $name, new Style(eol: Style::detectEol($code)), $phpVersion);
		} catch (RuleException $e) {
			throw new TestFailure($e->getPrevious() instanceof \LogicException ? $e->getPrevious()->getMessage() : $e->getMessage(), previous: $e);
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


	/** The version the fixture is written for, when it says so; `// php 8.4`. */
	private static function readPhpVersion(string $code, string $file): ?PhpVersion
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*php\s+(\S+)\s*$~i', $line, $m)) {
				try {
					return PhpVersion::fromString($m[1]);
				} catch (\InvalidArgumentException $e) {
					throw new TestFailure("$file: invalid version header: {$e->getMessage()}");
				}
			}
		}

		return null;
	}


	/**
	 * A fixture says nothing about the version when the rule works everywhere, and then it is tested at the
	 * lowest version it can meet; a rule of a newer construct at the version that construct came with.
	 */
	private static function defaultPhpVersion(Rule $rule): PhpVersion
	{
		return PhpVersion::fromString(RuleInfo::of($rule)->minPhpVersion ?? PhpVersion::Lowest);
	}


	/**
	 * The lines a fixture may put its headers on.
	 * @return list<string>
	 */
	private static function readHeader(string $code): array
	{
		return array_slice(preg_split('~\r?\n~', $code, 4), 0, 3);
	}


	/** @throws TestFailure */
	private static function read(string $file): string
	{
		$content = @file_get_contents($file); // @ - reported as exception
		return $content === false ? throw new TestFailure("Cannot read $file.") : $content;
	}
}
