<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Testing;

use DressCode\{
	Analyses, Config, ConfigurationException, ConvergenceException, Decision, ImportStyle,
	LineSplits, Profile, Rule, RuleException, RuleInfo, Style, Values, Violation,
};
use DressCode\Config\{Catalogue, DecisionResolver, Layer, LayerKind, PackageDiscovery, ProjectPackages, RuleBuilder};
use DressCode\Engine\{Diff, Gate, Helpers, PassLoop, PassResult, ReportPolicy, RulePlan};
use Nette\Neon\Neon;
use PhpSyntax\Analyses\NamespacedSymbols;
use PhpSyntax\{Lexer, Node, ParseException, Parser, Printer};
use PhpSyntax\Nodes\FileNode;
use function array_key_exists, count, is_array, sprintf, strlen;
use const JSON_INVALID_UTF8_SUBSTITUTE, JSON_THROW_ON_ERROR, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, PHP_OS_FAMILY, PHP_SAPI;


/**
 * Tests a rule over fixtures: the output must be the expected one and the rule must keep its contract
 * (idempotence, suppression, comments preserved, the parent invariant, no silent mutation, no risky violation
 * left where its fix was allowed, and what the last pass leaves in the tree is what a run over the output reports).
 * Works from any test framework; a failure is a TestFailure exception.
 */
final class RuleTester
{
	/** the widest line a fixture is checked with unless its header says another */
	private const DefaultLineLength = 120;

	/** @var array<string, Analyses\PhpStan>  by the stubs, the file of the code and the version of PHP */
	private static array $phpstan = [];


	/**
	 * Runs every *.code fixture in the directory: the output must equal <name>.expected (the input when
	 * there is none), the violations <name>.violations when present, a risky one followed by an indented line
	 * `risky <Risk>` with `: <because>` where the rule says it. A fixture sets what its run is given in the
	 * comments that open it: the values of the decisions `// {"key": value}`, a key without its section taking the
	 * one section of the rule, over those of `values.neon` beside the fixtures, the version of PHP it is
	 * written for `// php 8.4`, the widest line `// lineLength 80` (120 without it), that the run allows a fix that
	 * changes what the code does `// risky`, that the code before and after the fix is run and must print the same
	 * and exit alike `// run`, and what the namespaces declare outside it
	 * `// namespacedFunctions Acme\helper, Acme\Text\{format}`, `// namespacedConstants Acme\LIMIT` and
	 * `// nameResolution certain`. A rule that needs the types of the code
	 * gets them from the PHPStan of this project over the fixture and the declarations in the `stubs` directory
	 * beside it, and so does one that only does better with them where that directory is there, unless the fixture
	 * says `// types off`. Returns the count.
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses  the analyses of a plugin, as
	 *   `Config::$analyses` takes them
	 * @throws TestFailure
	 */
	public static function run(string|\Closure $rule, string $dir, ?string $phpVersion = null, array $analyses = []): int
	{
		$files = glob(rtrim($dir, '/\\') . '/*.code') ?: [];
		if (!$files) {
			throw new TestFailure("Directory `$dir` has no `*.code` fixtures.");
		}

		// before any fixture, so that those without the types do not load phpdoc-parser the others would mix with
		if (is_dir(rtrim($dir, '/\\') . '/stubs') || RuleInfo::fromRule($rule instanceof \Closure ? $rule() : $rule)->typesRequired) {
			Analyses\PhpStan::claimPhpDocParser();
		}

		foreach ($files as $file) {
			self::runFixture($rule, $file, $phpVersion, $analyses);
		}

		return count($files);
	}


	/**
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses
	 * @throws TestFailure
	 */
	public static function runFixture(string|\Closure $rule, string $file, ?string $phpVersion = null, array $analyses = []): void
	{
		$code = self::read($file);
		$base = (string) preg_replace('~\.code$~', '', $file);
		$expected = is_file("$base.expected") ? self::read("$base.expected") : null;
		$violations = is_file("$base.violations")
			? preg_split('~\r?\n~', trim(self::read("$base.violations")), -1, PREG_SPLIT_NO_EMPTY)
			: null;
		try {
			[$instance, $values] = self::createInstance($rule, $file, $code, $phpVersion);
			if ($instance === null) {
				if ($expected !== null || $violations) {
					throw new TestFailure('The values of the fixture leave the rule nothing to do, so it must change and report nothing.');
				}

				return;
			}

			self::check(
				rule: $instance,
				code: $code,
				expected: $expected,
				violations: $violations,
				phpVersion: $phpVersion ?? self::readPhpVersion($code),
				name: basename($file),
				fixRisky: self::readRisky($code),
				namespacedSymbols: self::readNamespacedSymbols($code),
				style: self::readStyle($code),
				stubs: self::findStubs($file, $code, $instance),
				analyses: $analyses,
				values: $values,
			);
			if (self::readRun($code)) {
				self::checkBehaviour($code, $expected ?? $code);
			}

		} catch (TestFailure $e) {
			throw new TestFailure("`$file`: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * What the rule reports over the fixture, line by line as the .violations file records it; for a tool
	 * that writes such a file.
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses
	 * @return list<string>
	 * @throws TestFailure
	 */
	public static function collectViolations(string|\Closure $rule, string $file, ?string $phpVersion = null, array $analyses = []): array
	{
		[, $result] = self::processFixture($rule, $file, $phpVersion, $analyses);
		return self::formatViolations($result->violations);
	}


	/**
	 * What the rule makes of the fixture; for a tool that writes the .expected file.
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses
	 * @throws TestFailure
	 */
	public static function collectOutput(string|\Closure $rule, string $file, ?string $phpVersion = null, array $analyses = []): string
	{
		[$node] = self::processFixture($rule, $file, $phpVersion, $analyses);
		return Printer::print($node);
	}


	/**
	 * The decisions under which the rule makes a fix that changes what the code does over the fixture, which only
	 * a fixture saying `// risky` lets it make; for the reference, which says what decisions have such a fix.
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses
	 * @return list<string>
	 * @throws TestFailure
	 */
	public static function collectRiskyDecisions(string|\Closure $rule, string $file, array $analyses = []): array
	{
		if (!self::readRisky(self::read($file))) {
			return [];
		}

		[, $result] = self::processFixture($rule, $file, null, $analyses);
		$made = array_filter($result->violations, fn(Violation $v) => $v->risk !== null && !$v->refused);
		return array_values(array_unique(array_map(fn(Violation $v) => $v->decision, $made)));
	}


	/**
	 * @param ?string $expected  the output; null when the rule must leave the code as it is
	 * @param ?list<string> $violations  "line: message" each, and the line of the risk after a risky one; null to skip the check
	 * @param NamespacedSymbols $namespacedSymbols  what the namespaces declare outside the code
	 * @param ?string $stubs  directory of the declarations the types of the code are computed with, which a rule that
	 *   does not need them gets too
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses  the analyses of a plugin, as
	 *   `Config::$analyses` takes them
	 * @param ?Style $style  the style the code is processed with; null is tabs and the widest line `DefaultLineLength`,
	 *   a given one is taken as it is, and the line ending is always that of the code
	 * @param ?Values $values  the values of the decisions of the rule, whose reports then pass the gate of the run; null
	 *   reports every requirement
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
		?Style $style = null,
		?string $stubs = null,
		array $analyses = [],
		?Values $values = null,
	): void
	{
		$phpVersion ??= self::getDefaultPhpVersion($rule);
		[$file, $result] = self::process(
			rule: $rule,
			code: $code,
			phpVersion: $phpVersion,
			name: $name,
			fixRisky: $fixRisky,
			namespacedSymbols: $namespacedSymbols,
			style: $style,
			stubs: $stubs,
			analyses: $analyses,
			values: $values,
		);
		self::checkParents($file);
		$output = Printer::print($file);
		if ($output !== ($expected ?? $code)) {
			throw new TestFailure($expected === null
				? "The output differs from the input, which must stay unchanged without an `.expected` file:\n"
					. Diff::unified($code, $output, "$name (input)", newPath: "$name (output)")
				: "The output differs from the expected one:\n"
					. Diff::unified($expected, $output, "$name (expected)", newPath: "$name (output)"));
		}

		if ($violations !== null) {
			$actual = self::formatViolations($result->violations);
			if ($actual !== $violations) {
				throw new TestFailure(
					"The violations differ from the expected ones:\n"
					. Diff::unified(implode("\n", $violations) . "\n", implode("\n", $actual) . "\n", "$name.violations"),
				);
			}
		}

		self::checkSpans($result->violations, $code);
		self::checkSpans($result->remaining ?? [], $output);
		if (!RuleInfo::fromRule($rule)->modifiesComments) {
			self::checkComments($code, $output);
		}

		[, $again] = self::process(
			rule: $rule,
			code: $output,
			phpVersion: $phpVersion,
			name: $name,
			fixRisky: $fixRisky,
			namespacedSymbols: $namespacedSymbols,
			style: $style,
			stubs: $stubs,
			analyses: $analyses,
			values: $values,
		);
		if ($again->mutated) {
			throw new TestFailure($again->violations
				? "The rule is not idempotent: it changes its own output again and reports:\n"
					. implode("\n", self::formatViolations($again->violations))
				: 'The rule is not idempotent: it changes its own output again.');
		}

		// a run takes what the last pass left in the tree for what a run over the output reports
		$reported = self::serializeViolations($again->violations);
		$remaining = $result->remaining === null ? $reported : self::serializeViolations($result->remaining);
		if ($remaining !== $reported) {
			throw new TestFailure(
				"What the last pass left in the tree differs from what a run over the output reports:\n"
				. Diff::unified(implode("\n", $reported) . "\n", implode("\n", $remaining) . "\n", "$name.remaining"),
			);
		}

		if ($result->violations && preg_match('~<\?php\b~i', $code)) {
			$ignored = (string) preg_replace('~<\?php(\s)~i', '<?php /* dresscode:ignoreFile */$1', $code, 1);
			[$ignoredFile, $ignoredResult] = self::process(
				rule: $rule,
				code: $ignored,
				phpVersion: $phpVersion,
				name: $name,
				fixRisky: $fixRisky,
				namespacedSymbols: $namespacedSymbols,
				style: $style,
				stubs: $stubs,
				analyses: $analyses,
				values: $values,
			);
			if ($ignoredResult->violations || Printer::print($ignoredFile) !== $ignored) {
				throw new TestFailure('The rule ignores the `dresscode:ignoreFile` comment: it still reports or changes the file.');
			}
		}
	}


	/**
	 * The fixture processed with what its header says.
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses
	 * @return array{FileNode, PassResult}
	 * @throws TestFailure
	 */
	private static function processFixture(string|\Closure $rule, string $file, ?string $phpVersion, array $analyses): array
	{
		$code = self::read($file);
		try {
			[$instance, $values] = self::createInstance($rule, $file, $code, $phpVersion);
			$instance ??= throw new TestFailure('The values of the fixture leave the rule nothing to do.');
			return self::process(
				rule: $instance,
				code: $code,
				phpVersion: $phpVersion ?? self::readPhpVersion($code) ?? self::getDefaultPhpVersion($instance),
				name: basename($file),
				fixRisky: self::readRisky($code),
				namespacedSymbols: self::readNamespacedSymbols($code),
				style: self::readStyle($code),
				stubs: self::findStubs($file, $code, $instance),
				analyses: $analyses,
				values: $values,
			);
		} catch (TestFailure $e) {
			throw new TestFailure("`$file`: {$e->getMessage()}", previous: $e);
		}
	}


	/**
	 * The rule as the header of the fixture configures it. The rule takes the values of `values.neon` beside the
	 * fixture, if there is one, under those of the header, which names a decision by its path or, for a rule of one
	 * section, without the section; with the values the run gates its reports. A rule a factory builds is configured
	 * with them too. Null where the values leave the rule nothing to do.
	 * @param class-string<Rule>|\Closure(): Rule $rule
	 * @return array{?Rule, Values}
	 * @throws TestFailure
	 */
	private static function createInstance(string|\Closure $rule, string $file, string $code, ?string $phpVersion): array
	{
		$instance = $rule instanceof \Closure ? $rule() : null;
		$class = $instance === null ? $rule : $instance::class;
		$decisions = Catalogue::collectDecisions($class);
		if ($decisions === []) {
			throw new TestFailure("Rule `$class` declares no decision.");
		} elseif ($problems = Catalogue::findLexiconProblems($decisions)) {
			throw new TestFailure("Rule `$class` breaks the lexicon of the values: " . implode('; ', $problems) . '.');
		}

		$layers = [];
		$base = dirname($file) . '/values.neon';
		if (is_file($base)) {
			$layers[] = [new Layer(LayerKind::Caller), (array) Neon::decodeFile($base)];
		}

		$tree = self::expandPaths($class, self::readOptions($code));
		$layers[] = [new Layer(LayerKind::Caller), $tree];
		$phpTarget = $phpVersion ?? self::readPhpVersion($code) ?? self::getDefaultPhpVersion($class);
		if (($tree['upgrading']['php']['deprecatedApi'] ?? 'keep') !== 'keep') {
			// the upgrading data of PHP lie beneath, as a run lays them
			$data = PackageDiscovery::readPhpData((new ProjectPackages)->withPhp($phpTarget)) ?? throw new \LogicException;
			array_unshift($layers, ...$data->buildDecisions(fn(string $map) => "upgrading.api.$map"));
		}

		$catalogue = Catalogue::fromRules(self::getRules($class, $layers));
		$resolver = new DecisionResolver(
			$catalogue,
			phpTarget: $phpTarget,
			project: null,
			typesAnalyzed: true,
			certainNames: self::readNamespacedSymbols($code)->complete,
		);
		try {
			$resolved = $resolver->resolve($layers);
		} catch (ConfigurationException $e) {
			throw new TestFailure($e->getMessage(), previous: $e);
		}

		$values = $resolver->createValues($resolved);
		$built = RuleBuilder::buildFromDecisions($resolver, $resolved, $values, $instance === null ? [] : [$class => fn() => $instance]);
		return [array_find($built, fn(Rule $built) => $built instanceof $class), $values];
	}


	/**
	 * The rule and the rules of the core owning a decision the layers name besides those of the rule, which it reads.
	 * @param  class-string<Rule>  $rule
	 * @param  list<array{Layer, array<string, mixed>}>  $layers
	 * @return list<class-string<Rule>>
	 */
	private static function getRules(string $rule, array $layers): array
	{
		static $owners;
		if ($owners === null) {
			$catalogue = (new Config\PluginRegistry)->getCatalogue();
			$owners = [];
			foreach (array_keys($catalogue->getDecisions()) as $path) {
				$owners[$path] = $catalogue->getRulesOf($path);
			}
		}

		$rules = [$rule];
		foreach ($owners as $path => $classes) {
			if (!in_array($rule, $classes, true) && array_any($layers, fn(array $layer) => self::holdsPath($layer[1], $path))) {
				array_push($rules, ...array_diff($classes, $rules));
			}
		}

		return $rules;
	}


	/** @param  array<string, mixed>  $tree */
	private static function holdsPath(array $tree, string $path): bool
	{
		foreach (explode('.', $path) as $link) {
			if (!is_array($tree) || !array_key_exists($link, $tree)) {
				return false;
			}

			$tree = $tree[$link];
		}

		return true;
	}


	/**
	 * The header of a fixture as a tree of sections, a key without its section completed with the one section
	 * of the rule; a key starting with another section of the core is a decision of another rule, which it reads.
	 * @param  class-string<Rule>  $rule
	 * @param  array<string, mixed>  $values
	 * @return array<string, mixed>
	 * @throws TestFailure
	 */
	private static function expandPaths(string $rule, array $values): array
	{
		$paths = array_map(fn(Decision $decision) => $decision->path, Catalogue::collectDecisions($rule));
		$sections = array_unique(array_map(fn(string $path) => explode('.', $path)[0], $paths));
		$tree = [];
		foreach ($values as $key => $value) {
			$path = (string) $key;
			$first = explode('.', $path)[0];
			$own = count($sections) === 1
				&& array_any(
					$paths,
					fn(string $declared) => str_starts_with("$declared.", $sections[array_key_first($sections)] . ".$key."),
				);
			if (!in_array($first, $sections, true) && ($own || !in_array($first, Catalogue::CoreSections, true))) {
				if (count($sections) !== 1) {
					throw new TestFailure("The header names `$key` without its section, which a rule of several sections cannot complete.");
				}

				$path = $sections[array_key_first($sections)] . ".$path";
			}

			$tree = Helpers::placeValue($tree, $path, $value);
		}

		return $tree;
	}


	/**
	 * The directory of declarations beside the fixture, when there is one and the header does not turn the types off
	 * with `// types off`, which a rule that needs them cannot run with.
	 * @throws TestFailure
	 */
	private static function findStubs(string $file, string $code, Rule $rule): ?string
	{
		if (!array_any(self::readHeader($code), fn(string $line) => preg_match('~^//\s*types\s+off\s*$~i', $line) === 1)) {
			$dir = dirname($file) . '/stubs';
			return is_dir($dir) ? $dir : null;
		} elseif (RuleInfo::fromRule($rule)->typesRequired) {
			throw new TestFailure('The header `// types off` cannot stand in a fixture of a rule with `typesRequired`; remove it.');
		}

		return null;
	}


	/**
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses
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
		?Style $style,
		?string $stubs = null,
		array $analyses = [],
		?Values $values = null,
	): array
	{
		try {
			$file = (new Parser)->parse($code);
		} catch (ParseException $e) {
			throw new TestFailure("The code does not parse: {$e->getMessage()}");
		}

		$style = ($style ?? new Style(maxLineLength: self::DefaultLineLength))->withLineEnding(Style::detectLineEnding($code));
		$registry = new Analyses\Registry($namespacedSymbols);
		if ($values !== null) {
			$style = $style->withImports(ImportStyle::fromValues($values))->withLineSplits(LineSplits::fromValues($values));
			Analyses\IndentationPlan::register($registry, $values, $style);
		}

		if (RuleInfo::fromRule($rule)->typesRequired || $stubs !== null) {
			self::registerTypes($registry, $code, $stubs, $phpVersion);
		}

		foreach (new Config(analyses: $analyses)->analyses as $class => $factory) {
			$registry->register($class, $factory);
		}

		$gates = $values === null ? [] : [$rule::class => Gate::fromValues(Catalogue::collectDecisions($rule::class), $values)];
		$runner = new PassLoop(new RulePlan([$rule], gates: $gates), $registry, new ReportPolicy(fixRisky: $fixRisky, strict: true));
		try {
			$result = $runner->run($file, $code, $name, $style, $phpVersion);
		} catch (RuleException $e) {
			throw new TestFailure($e->getMessage(), previous: $e);
		} catch (ConvergenceException $e) {
			throw new TestFailure($e->getMessage() . ($e->diff === '' ? '' : "\n$e->diff"), previous: $e);
		}

		return [$file, $result];
	}


	/**
	 * The types of the code from the PHPStan of this project. PHPStan reads the declarations of a file from the
	 * disk, so the code the run begins with goes to a file of its own, named by its text, and a later pass reads
	 * its own text as the command line does; a text seen before shares its PHPStan.
	 * @throws TestFailure  where the process loaded phpdoc-parser before, which the copy of PHPStan would mix with
	 */
	private static function registerTypes(Analyses\Registry $registry, string $code, ?string $stubs, string $phpVersion): void
	{
		Analyses\PhpStan::claimPhpDocParser();
		if (($class = Analyses\PhpStan::findMixedPhpDocParserClass()) !== null) {
			throw new TestFailure(
				"`$class` was loaded outside the archive of PHPStan before the types, so two copies of phpdoc-parser mix; "
				. 'run the fixtures with the types first or in a process of their own.',
			);
		}

		$dir = sys_get_temp_dir() . '/dresscode-tests/types';
		$path = $dir . '/' . hash('xxh128', $code) . '.php';
		$registry->register(Analyses\Types::class, function (FileNode $file) use ($code, $stubs, $phpVersion, $dir, $path): Analyses\Types {
			if (!is_file($path)) {
				@mkdir($dir, recursive: true); // @ directory may already exist
				file_put_contents($path, $code);
			}

			$phpstan = self::$phpstan["$stubs|$path|$phpVersion"] ??= new Analyses\PhpStan(
				$stubs ?? $dir,
				array_values(array_filter([$stubs, $path])),
				"$dir/cache",
				$phpVersion,
			);
			return new Analyses\Types($file, $path, $phpstan);
		});
	}


	/**
	 * Everything that identifies and places the violations, one line each.
	 * @param list<Violation> $violations
	 * @return list<string>
	 */
	private static function serializeViolations(array $violations): array
	{
		return array_map(fn(Violation $v) => json_encode([
			$v->decision,
			$v->message,
			$v->line,
			$v->column,
			$v->severity->name,
			$v->fingerprint,
			$v->risk?->name,
			$v->refused,
			$v->because,
			$v->derivedFrom,
			$v->offset,
			$v->length,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $violations);
	}


	/**
	 * The violations as the .violations file records them.
	 * @param list<Violation> $violations
	 * @return list<string>
	 */
	private static function formatViolations(array $violations): array
	{
		$lines = [];
		foreach ($violations as $v) {
			$lines[] = "$v->line: $v->message";
			if ($v->risk !== null) {
				$lines[] = "\trisky {$v->risk->name}" . ($v->because === null ? '' : ": $v->because");
			}
		}

		return $lines;
	}


	/** @throws TestFailure */
	private static function checkParents(Node $node): void
	{
		foreach ($node->getChildren() as $child) {
			if ($child->parent !== $node) {
				throw new TestFailure('The parent invariant is broken: `' . $child::class . '` under `' . $node::class . '` has another parent.');
			}

			if ($child instanceof Node) {
				self::checkParents($child);
			}
		}
	}


	/**
	 * Every span lies in the text the violations were found in and reaches the line they stand on.
	 * @param list<Violation> $violations
	 * @throws TestFailure
	 */
	private static function checkSpans(array $violations, string $text): void
	{
		foreach ($violations as $v) {
			if ($v->offset === null || $v->length === null) {
				continue;
			}

			$end = $v->offset + $v->length;
			$from = preg_match_all('~\r\n|\r|\n~', substr($text, 0, $v->offset)) + 1;
			$to = $from + preg_match_all('~\r\n|\r|\n~', substr($text, $v->offset, $v->length));
			if ($end > strlen($text) || $v->line < $from || $v->line > $to) {
				throw new TestFailure("The span of the violation on line $v->line, $v->message, covers lines $from to $to.");
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
						$comments[] = (string) preg_replace(['~\r\n?~', '~[ \t]+$~m'], ["\n", ''], $trivia->text);
					}
				}
			}

			sort($comments);
			return $comments;
		};
		$lost = array_diff_assoc($collect($before), $collect($after));
		if ($lost) {
			throw new TestFailure(
				'The rule lost or changed the ' . (count($lost) > 1 ? 'comments ' : 'comment ')
				. implode(', ', array_map(Violation::formatCode(...), $lost))
				. '; declare `modifiesComments` in its `#[RuleInfo]` if it changes them on purpose.',
			);
		}
	}


	/**
	 * @return array<string, mixed>
	 * @throws TestFailure
	 */
	private static function readOptions(string $code): array
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*(\{.*\})\s*$~', $line, $m)) {
				try {
					return json_decode($m[1], associative: true, flags: JSON_THROW_ON_ERROR);
				} catch (\JsonException $e) {
					throw new TestFailure("The options header is not valid JSON: {$e->getMessage()}.");
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


	/** `// run` in the header runs the code before and after the fix, which must print the same and exit alike. */
	private static function readRun(string $code): bool
	{
		return array_any(self::readHeader($code), fn(string $line) => preg_match('~^//\s*run\s*$~i', $line) === 1);
	}


	/**
	 * Runs the code and the fixed code in a process of their own each, under the CLI binary of PHP beside the running
	 * one, the two written to files of one name so that a path in a message is the same.
	 * @throws TestFailure
	 */
	private static function checkBehaviour(string $code, string $fixed): void
	{
		$php = PHP_SAPI === 'cli' ? PHP_BINARY : dirname(PHP_BINARY) . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
		$results = [];
		foreach (['before' => $code, 'after' => $fixed] as $side => $text) {
			$dir = sys_get_temp_dir() . '/dresscode-run-' . bin2hex(random_bytes(8));
			@mkdir($dir); // @ is checked by the write below
			$file = "$dir/fixture.php";
			if (@file_put_contents($file, $text) === false) { // @ is escalated to exception
				throw new TestFailure("Cannot write `$file`.");
			}

			exec(escapeshellarg($php) . ' -d display_errors=stdout ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
			$results[$side] = [str_replace([$file, strtr($file, '/', '\\')], 'fixture.php', implode("\n", $output)), $exit];
			$output = [];
			@unlink($file); // @ a scanner may hold the file
			@rmdir($dir); // @ and so the directory
		}

		if ($results['before'] !== $results['after']) {
			throw new TestFailure(sprintf(
				"The fixed code behaves otherwise than the code: it printed\n%s\nexiting with %d, where the code printed\n%s\nexiting with %d.",
				$results['after'][0],
				$results['after'][1],
				$results['before'][0],
				$results['before'][1],
			));
		}
	}


	/** The version the fixture is written for, when it says so; `// php 8.4`. */
	private static function readPhpVersion(string $code): ?string
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*php\s+(\S+)\s*$~i', $line, $m)) {
				return preg_match('~^\d+\.\d+$~D', $m[1])
					? $m[1]
					: throw new TestFailure("The header `// php $m[1]` must name a version such as `8.4`.");
			}
		}

		return null;
	}


	/** The style with the widest line the fixture is checked with, when it says so; `// lineLength 80`. */
	private static function readStyle(string $code): ?Style
	{
		foreach (self::readHeader($code) as $line) {
			if (preg_match('~^//\s*lineLength\s+(\S+)\s*$~i', $line, $m)) {
				return preg_match('~^[1-9]\d*$~D', $m[1])
					? new Style(maxLineLength: (int) $m[1])
					: throw new TestFailure("The header `// lineLength $m[1]` must name a positive whole number.");
			}
		}

		return null;
	}


	/**
	 * What the namespaces declare outside the fixture, the names listed the way a `use` statement lists them; without
	 * a header nothing is listed and nothing is known.
	 * @throws TestFailure
	 */
	private static function readNamespacedSymbols(string $code): NamespacedSymbols
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
			throw new TestFailure("The headers of the namespaces are not valid: {$e->getMessage()}");
		}

		return new NamespacedSymbols(
			$profile->namespaces['functions'],
			$profile->namespaces['constants'],
			$profile->nameResolution === 'certain',
		);
	}


	/**
	 * The items of a header, split at the commas outside a group, because a `use` statement takes a group of its own.
	 * @return list<string>
	 */
	private static function splitItems(string $items): array
	{
		return preg_split('~\s*,\s*(?![^{]*\})~', $items) ?: [];
	}


	/**
	 * A fixture says nothing about the version when the rule works everywhere, and then it is tested at the
	 * default target; a rule of a newer construct at the version that construct came with.
	 * @param  Rule|class-string<Rule>  $rule
	 */
	private static function getDefaultPhpVersion(Rule|string $rule): string
	{
		return RuleInfo::fromRule($rule)->getMinPhpVersion() ?? Config::DefaultPhpVersion;
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
		$content = @file_get_contents($file); // @ is escalated to exception
		return $content === false ? throw new TestFailure("Cannot read `$file`.") : $content;
	}
}
