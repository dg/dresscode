<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Profile};
use DressCode\Rules\ControlFlow\MultiLineConditionRule;
use DressCode\Rules\Literals\StringQuotesRule;
use DressCode\Rules\NodeHelpers;
use DressCode\Rules\Whitespace\IndentationRule;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{ParseException, Parser, SymbolKind};
use function array_slice, count, dirname, in_array, is_array, sprintf;
use const SORT_FLAG_CASE, SORT_STRING;


/**
 * The configuration `dresscode init` proposes for a project: the scope it finds, the standard it is given,
 * and the decisions that can be measured, each written as a value only where the code already says so.
 * Whatever it does not measure it leaves to the standard, and it never writes `fixRisky`.
 * @internal
 */
final class Proposal
{
	/**
	 * Where the code of a project usually is, beside what the autoload says: the test suites the autoload-dev
	 * rarely names and the entry points it cannot name at all. A name with a `*` is a pattern of the root.
	 */
	public const Paths = ['src', 'tests', 'test', 'app', 'lib', 'bin', 'cron', 'www*'];

	/** directories of files nobody writes by hand, left out on top of the default exclusions */
	public const GeneratedDirs = ['fixtures', 'Fixtures', 'expected'];

	/**
	 * The complete standards, the first of them the one written when none is given. Which is nearest is not
	 * measured, only what each would cost (`countChangedByStandard()`).
	 */
	public const Standards = ['per', 'psr12', 'nette', 'symfony'];

	/** the presets that know what a framework declares in its namespaces, by the package that brings them */
	public const FrameworkPresets = ['symfony/dependency-injection' => 'symfony-configurator'];

	/** the indentation units measured, as the configuration writes them */
	private const Indents = ['tab', '4', '2'];


	private function __construct(
		/** @var list<string> */
		public readonly array $presets,
		/** the presets were given, not the default */
		public readonly bool $given,
		/** @var list<string> */
		public readonly array $paths,
		/** @var list<string> */
		public readonly array $fileExtensions,
		/** @var list<string> */
		public readonly array $excludePaths,
		/** the files of the scope */
		public readonly int $total,
		public readonly Measurement $indent,
		public readonly Measurement $quotes,
		/** the shape of the conditions on several lines */
		public readonly Measurement $conditions,
		/** @var list<string>  fully qualified names of the functions the namespaces of the scope declare */
		public readonly array $namespacedFunctions,
		/** @var list<string>  fully qualified names of the constants the namespaces of the scope declare */
		public readonly array $namespacedConstants,
		/** @var list<string>  the files of the scope that do not parse, whose declarations are therefore unknown */
		public readonly array $unparsed,
		/** @var array<string, string>  preset => the installed package it is proposed for */
		public readonly array $frameworkPresets,
		/** the files everything above was measured on */
		public readonly Sample $sample,
		private readonly Survey $survey,
	) {
	}


	/**
	 * @param  ?list<string>  $presets  the standard, named as the configuration names it; null for the default
	 * @throws ConfigurationException
	 */
	public static function measure(string $root, ?array $presets = null): self
	{
		$paths = self::findPaths($root);
		$scope = (new RunnerFactory)->createRunner(new Config(fileExtensions: ['php', 'phpt']), $root, cache: false);
		$files = $generated = [];
		foreach ($scope->findFiles($paths) as $file) {
			$dirs = array_intersect(self::GeneratedDirs, explode('/', $file));
			if ($dirs) {
				$generated += array_flip($dirs);
			} else {
				$files[] = $file;
			}
		}

		$extensions = ['php', ...(array_filter($files, fn(string $file) => str_ends_with($file, '.phpt')) ? ['phpt'] : [])];
		$excludePaths = array_values(array_intersect(self::GeneratedDirs, array_keys($generated)));
		$files = array_values(array_filter($files, fn(string $file) => in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)));
		$sample = Sample::pick($root, $files);
		$survey = new Survey($root, $sample->files, new Config(excludePaths: $excludePaths, fileExtensions: $extensions));

		$indents = [];
		foreach (self::Indents as $indent) {
			$indents[$indent] = new Profile(rules: [IndentationRule::class => true], indent: self::toIndent($indent));
		}

		[$functions, $constants, $unparsed] = self::collectNamespacedDeclarations($root, $files);
		return new self(
			$presets ?? [self::Standards[0]],
			$presets !== null,
			$paths,
			$extensions,
			$excludePaths,
			count($files),
			$survey->measureFiles(IndentationRule::class, $indents),
			$survey->measurePlaces(StringQuotesRule::class, [
				'single' => new Profile(rules: [StringQuotesRule::class => 'single']),
				'double' => new Profile(rules: [StringQuotesRule::class => 'double']),
			], 'strings'),
			$survey->measurePlaces(
				MultiLineConditionRule::class,
				[
					'perLine' => new Profile(rules: [MultiLineConditionRule::class => ['shape' => 'perLine']]),
					'compact' => new Profile(rules: [MultiLineConditionRule::class => ['shape' => 'compact']]),
				],
				'conditions',
				new Profile(rules: [MultiLineConditionRule::class => ['shape' => ['perLine', 'compact']]]),
			),
			$functions,
			$constants,
			$unparsed,
			self::findFrameworkPresets($root),
			$sample,
			$survey,
		);
	}


	/**
	 * The scope of a project: the directories its autoload names, the conventional ones the root has besides
	 * them, and the root itself when neither says anything. A directory that lies in another one is already
	 * in the scope, and one that does not exist would only make the run throw.
	 * @return list<string>
	 */
	public static function findPaths(string $root): array
	{
		$paths = RunnerFactory::detectAutoloadPaths(RunnerFactory::findComposerFile($root), $root);
		foreach (self::Paths as $name) {
			// a pattern is matched against the names of the root, never against a path of its own, or a root
			// holding a bracket or a star would be part of the pattern and quietly match nothing
			$found = str_contains($name, '*')
				? array_values(array_filter(scandir($root) ?: [], fn(string $entry) => fnmatch($name, $entry) && is_dir("$root/$entry")))
				: (is_dir("$root/$name") ? [$name] : []);
			$paths = [...$paths, ...$found];
		}

		$scope = [];
		foreach (array_unique($paths) as $path) {
			foreach ($paths as $other) {
				if ($other !== $path && ($other === '.' || str_starts_with("$path/", "$other/"))) {
					continue 2;
				}
			}

			$scope[] = $path;
		}

		sort($scope, SORT_STRING);
		return $scope ?: ['.'];
	}


	/**
	 * The functions and constants the namespaces of the scope declare, read from every file and not from the sample,
	 * because a declaration left out would be taken as global where nothing reports it. Every file naming a namespace
	 * or `define()` is parsed, since a declaration can stand anywhere a statement can; one that does not parse is listed,
	 * its declarations unknown.
	 * @param  list<string>  $files
	 * @return array{list<string>, list<string>, list<string>}  functions, constants and the files that do not parse
	 */
	private static function collectNamespacedDeclarations(string $root, array $files): array
	{
		$parser = new Parser;
		$names = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$unparsed = [];
		foreach ($files as $file) {
			$code = @file_get_contents(RunnerFactory::toAbsolutePath($file, $root)); // @ - the file may be gone
			if ($code === false || (stripos($code, 'namespace') === false && stripos($code, 'define') === false)) {
				continue;
			}

			try {
				$node = $parser->parse($code);
			} catch (ParseException) {
				$unparsed[] = $file;
				continue;
			}

			foreach (NodeHelpers::findNamespacedDeclarations($node, new NameResolver($node)) as [$kind, $name]) {
				$names[$kind->name][Profile::toSymbolKey($kind, $name)] ??= $name;
			}
		}

		$lists = [];
		foreach ([SymbolKind::Function, SymbolKind::Constant] as $kind) {
			$list = array_values($names[$kind->name]);
			sort($list, SORT_STRING | SORT_FLAG_CASE);
			$lists[] = $list;
		}

		return [$lists[0], $lists[1], $unparsed];
	}


	/**
	 * The presets of the frameworks whose packages the project has, told by the lock file beside its composer.json
	 * or, without one, by what Composer installed, because an application rarely requires such a package itself.
	 * @return array<string, string>  preset => package
	 */
	private static function findFrameworkPresets(string $root): array
	{
		$composer = RunnerFactory::findComposerFile($root);
		$installed = [];
		foreach ([$composer === null ? null : dirname($composer) . '/composer.lock', "$root/vendor/composer/installed.json"] as $file) {
			$json = $file === null ? false : @file_get_contents($file); // @ - either file is optional
			$data = $json === false ? null : json_decode($json, associative: true);
			if (!is_array($data)) {
				continue;
			}

			$packages = array_is_list($data) ? $data : [...(array) ($data['packages'] ?? []), ...(array) ($data['packages-dev'] ?? [])];
			foreach ($packages as $package) {
				if (is_array($package) && isset($package['name'])) {
					$installed[(string) $package['name']] = true;
				}
			}

			break;
		}

		$frameworkPresets = [];
		foreach (self::FrameworkPresets as $package => $preset) {
			if (isset($installed[$package])) {
				$frameworkPresets[$preset] = $package;
			}
		}

		return $frameworkPresets;
	}


	/**
	 * The configuration as data, the same the text says; with a standard of its own what that standard would
	 * come to over the same decisions, which is what a price is measured on.
	 * @param  ?list<string>  $presets
	 */
	public function toConfig(?array $presets = null): Config
	{
		$indent = $this->indent->findPrevailing();
		$quotes = $this->quotes->findPrevailing();
		$shape = $this->findConditionShape();
		return new Config(
			presets: [...$presets ?? $this->presets, ...array_keys($this->frameworkPresets)],
			rules: array_filter([
				'string-quotes' => $quotes ?? ($this->quotes->opportunities ? false : null),
				'multi-line-condition' => $shape === null ? null : ['shape' => $shape],
			], fn($value) => $value !== null),
			indent: $indent === null ? null : self::toIndent($indent),
			namespaces: ['functions' => $this->namespacedFunctions, 'constants' => $this->namespacedConstants],
			nameResolution: 'certain',
			paths: $this->paths,
			excludePaths: $this->excludePaths,
			fileExtensions: $this->fileExtensions,
		);
	}


	/**
	 * The configuration as dresscode.neon, every measured value with the share it has in the code. A decision
	 * no value of which reaches the threshold is not written as a value.
	 */
	public function toNeon(): string
	{
		$sections = [
			sprintf(
				"# Written by dresscode init from %d of the %d files. A number is the share of the places a decision\n"
				. "# appears in that already agree with its value; what the file does not name, the standard decides.\n",
				$this->countSampled(),
				$this->total,
			),
			"presets:\n" . implode('', array_map(fn(string $preset) => "\t- $preset\n", $this->presets))
			. ($this->given ? '' : sprintf("\t# not chosen by measure; the other complete standards are %s\n", self::describeOthers()))
			. implode('', array_map(fn(string $preset, string $package) => "\t- $preset  # $package is installed\n", array_keys($this->frameworkPresets), $this->frameworkPresets)),
		];

		$indent = $this->indent->findPrevailing();
		if ($indent !== null) {
			$sections[] = sprintf("indent: %s  # %s\n", $indent, $this->indent->describe());
		} elseif ($this->indent->opportunities) {
			$sections[] = sprintf("# indent: %s; no value reaches %d%%, so the standard decides\n", $this->indent->describe(), 100 * Measurement::Threshold);
		}

		$lists = '';
		foreach (['functions' => $this->namespacedFunctions, 'constants' => $this->namespacedConstants] as $key => $names) {
			$lists .= $names ? "\t$key:\n" . implode('', array_map(fn(string $name) => "\t\t- $name\n", $names)) : '';
		}

		$sections[] = match (true) {
			$this->unparsed !== [] => "# {$this->describeUnparsed()}, so the namespaces may declare more than is listed here\n"
				. "# and an unqualified name in a namespace is not resolved for certain; once every file parses, init writes\n"
				. "# nameResolution: certain\n",
			$lists === '' => "# the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace\n"
				. "# is resolved for certain; one declared later is reported until it is listed\nnameResolution: certain\n",
			default => "# the namespaces of the scope declare these functions and constants and no others, so an unqualified name\n"
				. "# in a namespace is resolved for certain; one declared later is reported until it is listed\nnameResolution: certain\n",
		} . ($lists === '' ? '' : "namespaces:\n$lists");

		$rules = [];
		if ($this->quotes->opportunities) {
			// quotes have no tolerance, and a value half of the strings disagree with would rewrite them
			$rules[] = sprintf("\tstring-quotes: %s  # %s\n", $this->quotes->findPrevailing() ?? 'keep', $this->quotes->describe());
		}

		$shape = $this->findConditionShape();
		if ($shape !== null) {
			$rules[] = sprintf(
				"\tmulti-line-condition: {shape: %s}  # %s\n",
				is_array($shape) ? '[' . implode(', ', $shape) . ']' : $shape,
				$this->conditions->describe(),
			);
		}

		if ($rules) {
			$sections[] = "rules:\n" . implode('', $rules);
		}

		$sections[] = "paths:\n" . implode('', array_map(fn(string $path) => "\t- $path\n", $this->paths));
		if ($this->excludePaths) {
			$sections[] = "excludePaths:\n" . implode('', array_map(fn(string $path) => "\t- $path\n", $this->excludePaths));
		}

		if ($this->fileExtensions !== ['php']) {
			$sections[] = "fileExtensions:\n" . implode('', array_map(fn(string $ext) => "\t- $ext\n", $this->fileExtensions));
		}

		return implode("\n", $sections);
	}


	/** What the namespaces of the scope declare and which preset knows a framework, for the report of init. */
	public function describeNamespaces(): string
	{
		$counts = fn(int $count, string $noun) => match ($count) {
			0 => "no $noun",
			1 => "1 $noun",
			default => "$count {$noun}s",
		};
		return $counts(count($this->namespacedFunctions), 'function') . ' and ' . $counts(count($this->namespacedConstants), 'constant')
			. ' declared, ' . ($this->unparsed !== [] ? 'not resolved for certain, ' . $this->describeUnparsed() : 'resolved for certain')
			. implode('', array_map(fn(string $preset, string $package) => ", $preset for $package", array_keys($this->frameworkPresets), $this->frameworkPresets));
	}


	/** The files that do not parse, the first of them by name. */
	private function describeUnparsed(): string
	{
		$others = count($this->unparsed) - 1;
		return $this->unparsed[0] . match ($others) {
			0 => ' does not parse',
			1 => ' and 1 other file do not parse',
			default => " and $others other files do not parse",
		};
	}


	/**
	 * How many files of the sample the proposal would change, and how many a rule fails in.
	 * @return array{int, int}
	 */
	public function countChanged(): array
	{
		return $this->survey->countChanged($this->toConfig());
	}


	/**
	 * What each complete standard would cost over the same decisions, the cheapest first: the one number about
	 * the standards that measures a consequence and not a likeness, which is why it is told and no standard is
	 * chosen by it. A price comes with the files a rule of the standard fails in.
	 * @return array<string, array{int, int}>
	 * @throws ConfigurationException
	 */
	public function countChangedByStandard(): array
	{
		$prices = [];
		foreach (self::Standards as $standard) {
			$prices[$standard] = $this->survey->countChanged($this->toConfig([$standard]));
		}

		asort($prices);
		return $prices;
	}


	public function countSampled(): int
	{
		return count($this->sample->files);
	}


	/**
	 * What the run makes of the written configuration must be what was measured; anything else is a proposal
	 * that says one thing and does another.
	 * @throws \LogicException
	 */
	public function checkResolution(ResolvedConfig $resolved): void
	{
		$indent = $this->indent->findPrevailing();
		$quotes = $this->quotes->findPrevailing();
		$shape = $this->findConditionShape();
		$rule = $resolved->getRule('dresscode/string-quotes');
		$problem = match (true) {
			$indent !== null && $resolved->indent !== ($indent === 'tab' ? "\t" : str_repeat(' ', (int) $indent)) => "the indentation is not $indent",
			$quotes !== null && ($rule?->options['quotes'] ?? null) !== $quotes => "string-quotes is not $quotes",
			$quotes === null && $this->quotes->opportunities && $rule?->isActive() => 'string-quotes runs although it was kept',
			$shape !== null && ($resolved->getRule('dresscode/multi-line-condition')?->options['shape'] ?? null) !== $shape => 'multi-line-condition has another shape',
			($resolved->nameResolution === 'certain') !== ($this->unparsed === []) => 'the name resolution is not what the files that parse allow',
			array_diff($this->namespacedFunctions, array_keys($resolved->namespacedFunctions)) !== [] => 'a function the namespaces declare is not listed',
			array_diff($this->namespacedConstants, array_keys($resolved->namespacedConstants)) !== [] => 'a constant the namespaces declare is not listed',
			array_diff(array_map(fn(string $preset) => "dresscode/$preset", array_keys($this->frameworkPresets)), $resolved->presets) !== [] => 'a framework preset is not composed',
			default => null,
		};
		if ($problem !== null) {
			throw new \LogicException("The configuration dresscode init wrote does not say what it measured: $problem.");
		}
	}


	/** What the file says of the complete standards beside the one it writes by itself: "psr12, nette and symfony". */
	private static function describeOthers(): string
	{
		$others = array_slice(self::Standards, 1);
		$last = array_pop($others);
		return implode(', ', $others) . " and $last";
	}


	/**
	 * The shape the conditions on several lines are written in: the one that reaches the threshold, else the
	 * shapes the code has, the commonest first, when together they do, else keep, since the code writes its
	 * conditions in no shape the rule knows; null when the sample has no such condition.
	 * @return string|list<string>|null
	 */
	private function findConditionShape(): string|array|null
	{
		return $this->conditions->opportunities
			? $this->conditions->findPrevailing() ?? $this->conditions->findTolerated() ?? 'keep'
			: null;
	}


	/** @return int|'tab' */
	private static function toIndent(string $indent): int|string
	{
		return $indent === 'tab' ? 'tab' : (int) $indent;
	}
}
