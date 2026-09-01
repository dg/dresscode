<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Analyses\IndentationPlan;
use DressCode\{ConfigurationException, Decision, ImportStyle, Plugin, Rule, RuleInfo};
use function count, is_string;


/**
 * Every decision of the installed set, of the core, the plugins and the rules of the project, each with the rules
 * declaring it. A decision of one rule is declared by that rule; one several rules share is declared by a tree, the
 * decisions of the manifest of the core or of a plugin, and named by each of the rules in `RuleInfo::$decisions`, which
 * turns them all on, or in `RuleInfo::$reads` by a rule that only reads it, which turns nothing on. A decision of a
 * tree no registered rule names is not in the catalogue, except those the style of a run is read from
 * (`ImportStyle::Decisions`, `Analyses\IndentationPlan::Decisions`). The core keeps to its
 * sections, a plugin to the section named after it and the rules of the project to `project`. The order of the
 * registration is the order the rules run in, so that no order of the keys of a file changes what a run does.
 */
final class Catalogue
{
	/** the version of the shape of the export */
	public const Version = 3;

	/** the sections of the core, in the order a file writes them */
	public const CoreSections = [
		'file', 'indentation', 'naming', 'builtin', 'qualification', 'imports', 'spacing', 'multiline', 'braces',
		'blankLines', 'classes', 'types', 'functions', 'cleanup', 'controlFlow', 'expressions', 'literals',
		'comments', 'phpdoc', 'correctness', 'upgrading',
	];

	/** the keys of a file that are no section: the environment, the scope, the execution and `use` */
	public const ReservedKeys = [
		'use', 'namespaces', 'nameResolution', 'targets', 'paths', 'excludePaths',
		'fileExtensions', 'skipWhen', 'baseline', 'rules', 'analyses', 'fixRisky', 'warnOnly',
		'suppressionComments', 'overrides',
	];

	/** the section of the rules of the project */
	public const ProjectSection = 'project';

	/** @var array<string, Decision> */
	private array $decisions = [];

	/** @var array<string, list<class-string<Rule>>>  path => the rules declaring it, in the order of the registration */
	private array $owners = [];

	/** @var array<string, Decision>  path => a decision of a tree, which the rules naming it bring into the catalogue */
	private array $trees = [];

	/** @var list<class-string<Rule>> */
	private array $rules = [];

	/** @var array<class-string<Rule>, array<string, Decision>>  rule => the decisions it declares, by path */
	private array $byRule = [];

	/** @var array<string, list<class-string<Rule>>>  path => the rules reading it without enforcing it, in the order of the registration */
	private array $readers = [];

	/** @var array<string, list<string>>  the section of a plugin => the sections of the plugins it builds on, transitively */
	private readonly array $dependencies;


	/**
	 * @param  list<class-string<Rule>>  $coreRules
	 * @param  array<string, list<class-string<Rule>>>  $pluginRules  the section of a plugin => its rules, in the order of the plugins
	 * @param  list<class-string<Rule>>  $projectRules
	 * @param  list<Decision>  $coreDecisions  those the manifest of the core declares
	 * @param  array<string, list<Decision>>  $pluginDecisions  the section of a plugin => those its manifest declares
	 * @param  array<string, list<string>>  $pluginDependencies  the section of a plugin => the sections of the plugins it builds on, transitively
	 * @throws ConfigurationException
	 */
	public function __construct(
		array $coreRules,
		array $pluginRules = [],
		array $projectRules = [],
		array $coreDecisions = [],
		array $pluginDecisions = [],
		array $pluginDependencies = [],
	) {
		$this->dependencies = $pluginDependencies;
		$this->addTree($coreDecisions, self::CoreSections, 'the core');
		foreach ($pluginDecisions as $section => $decisions) {
			self::checkPluginSection($section);
			$this->addTree($decisions, [$section], "the plugin of section `$section`");
		}

		foreach ($coreRules as $rule) {
			$this->register($rule, self::CoreSections, 'the core');
		}

		foreach ($pluginRules as $section => $rules) {
			self::checkPluginSection($section);
			foreach ($rules as $rule) {
				$this->register($rule, [$section], "the plugin of section `$section`");
			}
		}

		foreach ($projectRules as $rule) {
			$this->register($rule, [self::ProjectSection], 'the project');
		}

		// a decision only read is known to the values, after those the rules enforce, and so are those the style of the
		// run is read from, which a rule writing code takes whether or not the rule enforcing them runs
		foreach ([...array_keys($this->readers), ...ImportStyle::Decisions, ...IndentationPlan::Decisions] as $path) {
			if (isset($this->trees[$path])) {
				$this->decisions[$path] ??= $this->trees[$path];
			}
		}

		$this->checkStructures();
	}


	/**
	 * The catalogue of the rules alone, for a place that runs them without a configuration, as a test does: a rule
	 * deciding outside the sections of the core is one of a plugin named so, or of the project, and the trees are those
	 * of the core and of the plugin of the package each rule lies in.
	 * @param  list<class-string<Rule>>  $rules
	 * @throws ConfigurationException
	 */
	public static function fromRules(array $rules): self
	{
		$core = $plugins = $project = $pluginDecisions = [];
		foreach ($rules as $class) {
			$own = $class::getDecisions();
			$section = explode('.', $own === [] ? RuleInfo::of($class)->decisions[0] ?? '' : $own[0]->path)[0];
			match (true) {
				in_array($section, self::CoreSections, true) || in_array($section, self::ReservedKeys, true) => $core[] = $class,
				$section === self::ProjectSection => $project[] = $class,
				default => $plugins[$section][] = $class,
			};
		}

		$dependencies = [];
		foreach ($plugins as $section => $classes) {
			$trees = self::collectPluginTrees(PackageDiscovery::findPluginOf($classes[0]));
			$pluginDecisions += $trees;
			$dependencies[$section] = array_values(array_diff(array_keys($trees), [$section]));
		}

		return new self($core, $plugins, $project, (new CorePlugin)->getManifest()->decisions, $pluginDecisions, $dependencies);
	}


	public function find(string $path): ?Decision
	{
		return $this->decisions[$path] ?? null;
	}


	/**
	 * The rules declaring the decision, in the order of the registration.
	 * @return list<class-string<Rule>>
	 */
	public function getRulesOf(string $path): array
	{
		return $this->owners[$path] ?? [];
	}


	/**
	 * The rules reading the decision without enforcing it, in the order of the registration.
	 * @return list<class-string<Rule>>
	 */
	public function getReadersOf(string $path): array
	{
		return $this->readers[$path] ?? [];
	}


	/** @return array<string, Decision>  by path, in the order of the registration */
	public function getDecisions(): array
	{
		return $this->decisions;
	}


	/**
	 * The decisions of a section or a structure, or the one decision of the path.
	 * @return array<string, Decision>
	 */
	public function getDecisionsUnder(string $prefix): array
	{
		return array_filter(
			$this->decisions,
			fn(Decision $decision) => $decision->path === $prefix || str_starts_with($decision->path, "$prefix."),
		);
	}


	/**
	 * The decisions the rule declares.
	 * @param  class-string<Rule>  $rule
	 * @return array<string, Decision>
	 */
	public function getDecisionsOf(string $rule): array
	{
		return $this->byRule[$rule] ?? [];
	}


	/**
	 * The decisions the rule declares and those of a tree it names, of the core or of the plugin of the package the rule
	 * lies in, for a place that has no catalogue, as a test running one rule.
	 * @param  class-string<Rule>  $rule
	 * @return list<Decision>
	 * @throws ConfigurationException  for a path no tree declares
	 */
	public static function collectDecisions(string $rule): array
	{
		static $core;
		$core ??= (new CorePlugin)->getManifest()->decisions;
		$named = RuleInfo::of($rule)->decisions;
		$trees = $named === [] ? [] : array_column([...$core, ...array_merge(...array_values(self::collectPluginTrees(PackageDiscovery::findPluginOf($rule))))], null, 'path');
		$decisions = [];
		foreach ($named as $path) {
			$decisions[] = $trees[$path] ?? throw new ConfigurationException("Rule `$rule` names `$path`, which no tree declares.");
		}

		return [...$decisions, ...$rule::getDecisions()];
	}


	/** @return list<class-string<Rule>>  the order the rules run in, that of the registration */
	public function getRuleOrder(): array
	{
		return $this->rules;
	}


	/**
	 * The catalogue as data: every decision with its domain, its kind, its description and notes, its default,
	 * the rules declaring it and what each of them requires, and the rules reading it.
	 * @return array{version: int, decisions: array<string, array<string, mixed>>}
	 */
	public function toArray(): array
	{
		$decisions = [];
		foreach ($this->decisions as $path => $decision) {
			$rules = [];
			foreach ($this->owners[$path] ?? [] as $rule) {
				$rules[$rule] = RuleInfo::of($rule)->requires;
			}

			$decisions[$path] = $decision->toArray() + ['rules' => $rules, 'readers' => $this->readers[$path] ?? []];
		}

		return ['version' => self::Version, 'decisions' => $decisions];
	}


	/**
	 * @param  class-string<Rule>  $rule
	 * @param  list<string>  $sections
	 * @throws ConfigurationException
	 */
	private function register(string $rule, array $sections, string $who): void
	{
		if (!is_subclass_of($rule, Rule::class)) {
			throw new ConfigurationException("Class `$rule` is not a rule.");
		} elseif (in_array($rule, $this->rules, true)) {
			throw new ConfigurationException("Rule `$rule` is registered twice.");
		}

		$info = RuleInfo::of($rule);
		$declared = $rule::getDecisions();
		if ($declared === [] && $info->decisions === []) {
			throw new ConfigurationException("Rule `$rule` declares no decision.");
		}

		// what the rule shares stands before what is its own, the general before the particular
		foreach ($info->decisions as $path) {
			$this->add($rule, $this->findTree($rule, $path, $sections, $who));
		}

		foreach ($info->reads as $path) {
			$this->findTree($rule, $path, $sections, $who);
			$this->readers[$path][] = $rule;
		}

		foreach ($declared as $decision) {
			$path = $decision->path;
			$section = explode('.', $path, 2)[0];
			if ($decision->fact ? !in_array($section, self::ReservedKeys, true) : !in_array($section, $sections, true)) {
				throw new ConfigurationException($decision->fact
					? "Rule `$rule` declares the fact `$path`, which is no key of the environment."
					: "Rule `$rule` declares `$path` outside " . (count($sections) > 1 ? 'the sections' : 'the section `' . $sections[0] . '`') . " of $who.");
			} elseif (isset($this->trees[$path])) {
				throw new ConfigurationException("Rule `$rule` declares `$path`, which a tree declares; the rule names it in `RuleInfo::\$decisions`.");
			} elseif (isset($this->decisions[$path])) {
				throw new ConfigurationException("Decision `$path` is declared by both `{$this->owners[$path][0]}` and `$rule`; a decision several rules share is declared by a tree and named by each of them.");
			}

			$this->add($rule, $decision);
		}

		$this->rules[] = $rule;
	}


	/**
	 * The decision of a tree the rule names: a plugin names the trees of the core, its own and those of the plugins it
	 * builds on, the project any.
	 * @param  class-string<Rule>  $rule
	 * @param  list<string>  $sections
	 * @throws ConfigurationException
	 */
	private function findTree(string $rule, string $path, array $sections, string $who): Decision
	{
		$tree = explode('.', $path, 2)[0];
		$reached = match ($sections) {
			[self::ProjectSection] => null,
			self::CoreSections => self::CoreSections,
			default => [...self::CoreSections, ...$sections, ...$this->dependencies[$sections[0]] ?? []],
		};
		if (!isset($this->trees[$path])) {
			throw new ConfigurationException("Rule `$rule` names `$path`, which no tree declares.");
		} elseif ($reached !== null && !in_array($tree, $reached, true)) {
			throw new ConfigurationException("Rule `$rule` names `$path` of the plugin of section `$tree`, which $who does not build on"
				. ($sections === self::CoreSections ? '.' : '; add that plugin to the `plugins` of its manifest.'));
		}

		return $this->trees[$path];
	}


	/**
	 * @param  list<Decision>  $decisions
	 * @param  list<string>  $sections
	 * @throws ConfigurationException
	 */
	private function addTree(array $decisions, array $sections, string $who): void
	{
		foreach ($decisions as $decision) {
			if (!in_array(explode('.', $decision->path, 2)[0], $sections, true)) {
				throw new ConfigurationException("The tree of $who declares `$decision->path` outside " . (count($sections) > 1 ? 'its sections' : 'the section `' . $sections[0] . '`') . '.');
			} elseif ($decision->fact) {
				throw new ConfigurationException("The tree of $who declares the fact `$decision->path`; a fact is declared by the rule guarding it.");
			} elseif (isset($this->trees[$decision->path])) {
				throw new ConfigurationException("Decision `$decision->path` is declared by two trees.");
			}

			$this->trees[$decision->path] = $decision;
		}
	}


	/**
	 * The decisions the manifest of the plugin and of every plugin it builds on declare, by the section of each.
	 * @param  array<class-string<Plugin>, true>  $visited
	 * @return array<string, list<Decision>>
	 */
	private static function collectPluginTrees(?Plugin $plugin, array &$visited = []): array
	{
		if ($plugin === null || isset($visited[$plugin::class])) {
			return [];
		}

		$visited[$plugin::class] = true;
		$manifest = $plugin->getManifest();
		$trees = $manifest->section === null ? [] : [$manifest->section => $manifest->decisions];
		foreach ($manifest->plugins as $dependency) {
			$trees += self::collectPluginTrees(is_string($dependency) ? new $dependency : $dependency, $visited);
		}

		return $trees;
	}


	/** @throws ConfigurationException */
	private static function checkPluginSection(string $section): void
	{
		if (
			in_array($section, self::ReservedKeys, true)
			|| in_array($section, self::CoreSections, true)
			|| $section === self::ProjectSection
		) {
			throw new ConfigurationException("Plugin section `$section` is a key of the configuration or a section of the core; name the section after the plugin.");
		}
	}


	/** @param  class-string<Rule>  $rule */
	private function add(string $rule, Decision $decision): void
	{
		$this->decisions[$decision->path] ??= $decision;
		$this->owners[$decision->path][] = $rule;
		$this->byRule[$rule][$decision->path] = $decision;
	}


	/**
	 * A structure holds decisions and a decision is atomic, its keys being data, so no path is both.
	 * @throws ConfigurationException
	 */
	private function checkStructures(): void
	{
		$paths = array_keys($this->decisions);
		sort($paths);
		foreach ($paths as $i => $path) {
			$next = $paths[$i + 1] ?? '';
			if (str_starts_with($next, "$path.")) {
				throw new ConfigurationException("Decision `$path` is also a structure holding `$next` (rules `{$this->owners[$path][0]}` and `{$this->owners[$next][0]}`).");
			}
		}
	}
}
