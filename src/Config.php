<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\Nodes\FileNode;
use function is_callable, is_int, is_string;


/**
 * The configuration of a project, written as dresscode.neon or dresscode.php, whose keys are the parameters here: the
 * profile of the whole project, the overrides for parts of its tree, and the scope of a run, what it checks and what it
 * keeps. A key left out keeps its default.
 */
final readonly class Config extends Profile
{
	/** dependencies, temporary and log directories, and anything dot-prefixed (.git, .idea, .scratch) */
	public const DefaultExcludePaths = ['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'];

	/**
	 * the oldest version DressCode fixes code for; the rules never ask whether the target has what it already has
	 * @internal
	 */
	public const MinPhpVersion = '8.0';

	/** the version the rules target when neither the configuration nor a composer.json says one */
	public const DefaultPhpVersion = '8.0';

	/** @var list<string>  patterns relative to the root: the default ones and those the configuration adds, each once */
	public array $excludePaths;

	/** @var ?\Closure(string, string): bool  files left out by their content and path */
	public ?\Closure $skipWhen;

	/** @var array<class-string, ?\Closure(FileNode, string): object>  analysis => its factory given the file and its path, or null when the engine builds it with the file or with nothing */
	public array $analyses;


	/**
	 * @param list<string> $presets
	 * @param list<string|RuleGroup> $groups
	 * @param array<string, bool|string|int|array<string, mixed>|\Closure(): Rule> $rules
	 * @param array{functions?: list<string>, constants?: list<string>} $namespaces
	 * @param list<string> $fixRisky
	 * @param array<string, string> $targets  `php` => the version the code is written for
	 * @param list<string> $warnOnly
	 * @param list<string> $excludePaths  left out of the run on top of the default list
	 * @param ?callable(string $content, string $path): bool $skipWhen
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses  a class the engine builds itself, or a class with its factory given the file and its path
	 */
	public function __construct(
		/** @var list<class-string<Plugin>|Plugin>  what packages bring to the project, by class or as an object */
		public array $plugins = [],
		array $presets = [],
		array $groups = [],
		array $rules = [],
		int|string|null $indent = null,
		?string $lineEnding = null,
		int|false|null $lineLength = null,
		array $targets = [],
		array $namespaces = [],
		?string $nameResolution = null,
		array $fixRisky = [],
		array $warnOnly = [],
		/** @var list<Override> */
		public array $overrides = [],
		/** @var list<string>  files and directories relative to the root, checked when the command line names none */
		public array $paths = [],
		array $excludePaths = [],
		/** @var list<string>  the extensions of the files to check, without a dot */
		public array $fileExtensions = ['php'],
		?callable $skipWhen = null,
		/** .neon or .php file of violations left unreported, relative to the root; `dresscode baseline` writes it */
		public ?string $baseline = null,
		public ?string $cacheDir = null,
		array $analyses = [],
		/** `'phpstan'` takes the types of the code from the PHPStan of the project; without it no rule that needs them runs */
		public ?string $types = null,
		/** the address of the page of each rule the configuration names by class, `{slug}` standing for the name without its vendor */
		public ?string $ruleUrl = null,
	) {
		parent::__construct($presets, $groups, $rules, $indent, $lineEnding, $lineLength, $targets, $namespaces, $nameResolution, $fixRisky, $warnOnly);
		if ($types !== null && $types !== 'phpstan') {
			throw new \InvalidArgumentException("The types must be `phpstan`, `$types` given.");
		}

		self::checkPlugins($plugins);
		self::checkRuleUrl($ruleUrl);

		$this->excludePaths = array_values(array_unique([...self::DefaultExcludePaths, ...$excludePaths]));
		$this->skipWhen = $skipWhen === null ? null : $skipWhen(...);
		$this->analyses = self::normalizeAnalyses($analyses);
	}


	/**
	 * @param  array<mixed>  $plugins
	 * @throws \InvalidArgumentException
	 * @internal
	 */
	public static function checkPlugins(array $plugins): void
	{
		foreach ($plugins as $plugin) {
			if (!$plugin instanceof Plugin && !(is_string($plugin) && is_subclass_of($plugin, Plugin::class))) {
				throw new \InvalidArgumentException('Plugin `' . (is_string($plugin) ? $plugin : get_debug_type($plugin)) . '` is not a plugin; a rule is named in `rules` and a preset in `presets`.');
			}
		}
	}


	/**
	 * @throws \InvalidArgumentException
	 * @internal
	 */
	public static function checkRuleUrl(?string $ruleUrl): void
	{
		if ($ruleUrl !== null && !preg_match('~^[a-z][a-z0-9+.-]*://[^\x00-\x20\x7F]+$~Di', $ruleUrl)) {
			throw new \InvalidArgumentException("Invalid `ruleUrl` `$ruleUrl`, an address such as `https://acme.dev/rules/{slug}` is expected.");
		}
	}


	/**
	 * @param  array<string|int, string|callable(FileNode, string): object>  $analyses
	 * @return array<class-string, ?\Closure(FileNode, string): object>
	 * @throws \InvalidArgumentException
	 * @internal
	 */
	public static function normalizeAnalyses(array $analyses): array
	{
		$normalized = [];
		foreach ($analyses as $key => $value) {
			[$class, $factory] = is_int($key) ? [$value, null] : [$key, $value];
			if (!is_string($class) || !class_exists($class)) {
				throw new \InvalidArgumentException('Analysis class `' . (is_string($class) ? $class : get_debug_type($class)) . '` does not exist.');
			} elseif ($factory === null && !Analyses\Registry::isConstructible($class)) {
				throw new \InvalidArgumentException("Analysis `$class` must take the `FileNode` or nothing in its constructor, or come with a factory.");
			} elseif ($factory !== null && !is_callable($factory)) {
				throw new \InvalidArgumentException("The factory of analysis `$class` must be callable, `" . get_debug_type($factory) . '` given.');
			}

			$normalized[$class] = $factory === null ? null : $factory(...);
		}

		return $normalized;
	}
}
