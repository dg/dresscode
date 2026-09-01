<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\Nodes\FileNode;
use function is_int, is_string;


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

	/** @var list<class-string<Plugin>|Plugin>  the plugins `use` names, which make their rules, presets and analyses known */
	public array $plugins;

	/** @var array<class-string<Rule>, ?\Closure(): Rule>  the rules of the project, each with its factory or null where the engine builds it */
	public array $rules;


	/**
	 * @param list<string|Plugin> $use  presets by name, files of the same shape by their path, and plugins by class or as objects
	 * @param array<int|class-string<Rule>, class-string<Rule>|\Closure(): Rule> $rules  the rules of the project, each by its class, or its class with the factory of a rule with dependencies
	 * @param array{functions?: list<string>, constants?: list<string>} $namespaces
	 * @param list<string> $fixRisky
	 * @param array<string, string> $targets  `php` => the version the code is written for
	 * @param list<string> $warnOnly
	 * @param array<string, string|list<string>> $suppressionComments
	 * @param list<string> $excludePaths  left out of the run on top of the default list
	 * @param ?callable(string $content, string $path): bool $skipWhen
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses  a class the engine builds itself, or a class with its factory given the file and its path
	 * @param array<string, mixed> $decisions
	 */
	public function __construct(
		array $use = [],
		array $rules = [],
		array $targets = [],
		array $namespaces = [],
		?string $nameResolution = null,
		array $fixRisky = [],
		array $warnOnly = [],
		array $suppressionComments = [],
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
		/** the address of the page of each rule of the project, `{slug}` standing for its class without the suffix, the first letter in lower case */
		public ?string $ruleUrl = null,
		array $decisions = [],
	) {
		$plugins = array_filter($use, fn($entry) => $entry instanceof Plugin || is_subclass_of($entry, Plugin::class));
		parent::__construct(array_values(array_diff_key($use, $plugins)), $targets, $namespaces, $nameResolution, $fixRisky, $warnOnly, $suppressionComments, $decisions);
		$this->plugins = array_values($plugins);
		$this->rules = self::normalizeRules($rules);
		Config\ManifestFields::checkRuleUrl($ruleUrl);
		Config\ManifestFields::checkGlobs($excludePaths, 'excludePaths');

		$this->excludePaths = array_values(array_unique([...self::DefaultExcludePaths, ...$excludePaths]));
		$this->skipWhen = $skipWhen === null ? null : $skipWhen(...);
		$this->analyses = Config\ManifestFields::normalizeAnalyses($analyses);
	}


	/**
	 * The factories of the rules of the project that the configuration builds itself.
	 * @return array<class-string<Rule>, \Closure(): Rule>
	 */
	public function getRuleFactories(): array
	{
		return array_filter($this->rules);
	}


	/**
	 * @param  array<int|string, mixed>  $rules
	 * @return array<class-string<Rule>, ?\Closure(): Rule>
	 * @throws \InvalidArgumentException
	 */
	private static function normalizeRules(array $rules): array
	{
		$normalized = [];
		foreach ($rules as $key => $value) {
			[$class, $factory] = is_int($key) ? [$value, null] : [$key, $value];
			if (!is_string($class) || !is_subclass_of($class, Rule::class)) {
				throw new \InvalidArgumentException('`rules` names the classes of the rules of the project, `' . (is_string($class) ? $class : get_debug_type($class)) . '` is not one.');
			} elseif ($factory !== null && !$factory instanceof \Closure) {
				throw new \InvalidArgumentException("The factory of rule `$class` must be a closure, `" . get_debug_type($factory) . '` given.');
			}

			$normalized[$class] = $factory;
		}

		return $normalized;
	}
}
