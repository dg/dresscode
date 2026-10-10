<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\Nodes\FileNode;


/**
 * What a plugin brings to a project: rules and presets known by their names, the plugins it builds on, the analyses
 * it builds, the paths it leaves out and the files it skips, and the address of the page of each of its rules.
 */
final readonly class PluginManifest
{
	/** @var list<string>  patterns relative to the root */
	public array $excludePaths;

	/** @var ?\Closure(string, string): bool  files left out by their content and path */
	public ?\Closure $skipWhen;

	/** @var array<class-string, ?\Closure(FileNode, string): object>  analysis => its factory given the file and its path, or null when the engine builds it with the file or with nothing */
	public array $analyses;


	/**
	 * @param list<string> $excludePaths  left out of the run on top of what the project leaves out
	 * @param ?callable(string $content, string $path): bool $skipWhen
	 * @param array<string|int, string|callable(FileNode, string): object> $analyses  a class the engine builds itself, or a class with its factory given the file and its path
	 */
	public function __construct(
		/** @var list<class-string<Rule>> */
		public array $rules = [],
		/** @var array<string, string>  name of a preset => the file of the shape of a configuration that it is */
		public array $presets = [],
		/** @var list<class-string<Plugin>|Plugin>  the plugins it builds on */
		public array $plugins = [],
		array $analyses = [],
		array $excludePaths = [],
		?callable $skipWhen = null,
		/** the address of the page of each of its rules, `{slug}` standing for its class without the suffix, the first letter in lower case */
		public ?string $ruleUrl = null,
		/** the section the decisions of its rules lie in, named after the plugin; a plugin bringing rules needs one */
		public ?string $section = null,
		/** @var list<Decision>  the decisions of its section no single rule of it owns, each turning on the rules that name it in `RuleInfo::$decisions` */
		public array $decisions = [],
	) {
		// the rules and the presets are loaded when the run registers them, so that a run loads only the rules it runs
		Config\ManifestFields::checkPlugins($plugins, 'a rule is named in `rules` and a preset in `presets`');
		Config\ManifestFields::checkRuleUrl($ruleUrl);
		Config\ManifestFields::checkGlobs($excludePaths, 'excludePaths');
		$this->excludePaths = array_values(array_unique($excludePaths));
		$this->skipWhen = $skipWhen === null ? null : $skipWhen(...);
		$this->analyses = Config\ManifestFields::normalizeAnalyses($analyses);
	}
}
