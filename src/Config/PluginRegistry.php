<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Decision, Rule};
use DressCode\Interop\Translator;
use DressCode\Rules\QualifiedNames;
use Nette\Utils\Helpers;
use function array_key_exists, strlen;


/**
 * What a run knows of its plugins: the rules by their classes, the presets by name, the pages of their documentation,
 * the sections, the translator and the catalogue. A name of a preset without a vendor is the
 * one of the core of that name, so `'perCs'` is `'dresscode/perCs'`.
 * @internal
 */
final class PluginRegistry
{
	private const Vendor = 'dresscode/';

	/** the page of a decision of the core, the path following it */
	private const DecisionUrl = 'https://dresscode.run/decisions/';

	/** @var list<class-string<Rule>>  in the order of their registration */
	public private(set) array $rules = [];

	/** @var array<string, string>  name => the file of the preset */
	public private(set) array $presets = [];

	/** @var array<string, true>  the packages whose plugins the project lets in */
	private array $pluginPackages = [];

	/** @var array<class-string<Rule>, string>  rule => the address of its page */
	private array $urls = [];

	/** @var array<class-string<Rule>, ?string>  a rule that is not of the core => the section of the plugin bringing it, null for one of the project */
	private array $sections = [];

	/** @var list<Decision>  the decisions the core declares for several of its rules */
	private readonly array $coreDecisions;

	/** @var array<string, list<Decision>>  the section of a plugin => the decisions its manifest declares */
	private array $pluginDecisions = [];

	/** @var array<string, list<string>>  the section of a plugin => the sections of the plugins it builds on, transitively */
	private array $pluginDependencies = [];

	private ?Catalogue $catalogue = null;


	public function __construct(
		public readonly Translator $translator = new Translator,
	) {
		$core = (new CorePlugin)->getManifest();
		$this->rules = $core->rules;
		$this->coreDecisions = $core->decisions;
		foreach ($core->presets as $name => $file) {
			$this->registerPreset($name, $file);
		}
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  ?string  $url  the address of the page of the rule, `{slug}` standing for its class without the suffix, the first letter in lower case
	 * @param  ?string  $section  the section of the plugin bringing it, null for a rule of the project
	 * @throws ConfigurationException
	 */
	public function registerRule(string $class, ?string $url = null, ?string $section = null): void
	{
		if (!is_subclass_of($class, Rule::class)) {
			throw new ConfigurationException("Class `$class` is not a rule.");
		}

		$known = in_array($class, $this->rules, true);
		if (!$known) {
			$this->rules[] = $class;
		}

		if (!$known || $section !== null) {
			$this->sections[$class] = $section;
			$this->catalogue = null;
		}

		if ($url !== null) {
			$short = QualifiedNames::stripNamespace($class);
			$this->urls[$class] = str_replace('{slug}', lcfirst(str_ends_with($short, 'Rule') ? substr($short, 0, -4) : $short), $url);
		}
	}


	/**
	 * The decisions the manifest of a plugin declares for several of its rules.
	 * @param  list<Decision>  $decisions
	 */
	public function registerDecisions(string $section, array $decisions): void
	{
		$this->pluginDecisions[$section] = $decisions;
		$this->catalogue = null;
	}


	/**
	 * The plugins the plugin of the section builds on, by their sections, whose trees its rules may name.
	 * @param  list<string>  $sections
	 */
	public function registerDependencies(string $section, array $sections): void
	{
		$this->pluginDependencies[$section] = $sections;
		$this->catalogue = null;
	}


	/** @param  class-string<Rule>  $class */
	public function findRuleUrl(string $class): ?string
	{
		return $this->urls[$class] ?? null;
	}


	/**
	 * The registered rules by where they come from, each in the order of its registration: those of the core, those of
	 * each plugin under its section, and those of the project.
	 * @return array{list<class-string<Rule>>, array<string, list<class-string<Rule>>>, list<class-string<Rule>>}
	 */
	public function getRulesByOrigin(): array
	{
		$core = $plugins = $project = [];
		foreach ($this->rules as $class) {
			if (!array_key_exists($class, $this->sections)) {
				$core[] = $class;
			} elseif ($this->sections[$class] === null) {
				$project[] = $class;
			} else {
				$plugins[$this->sections[$class]][] = $class;
			}
		}

		return [$core, $plugins, $project];
	}


	/**
	 * A name nobody owns, with the decisions that cover it where it belongs to another tool, else the suggestion.
	 */
	private function createUnknownNameException(string $name, string $message, string $suggestion): ConfigurationException
	{
		$covered = $this->translator->findPaths($name);
		return match (true) {
			$covered !== [] => new ConfigurationException("$message It is covered by `" . implode('` and `', $covered) . '`; `dresscode import` translates a configuration of another tool.', docs: 'migration#import'),
			default => new ConfigurationException($message . $suggestion),
		};
	}


	/**
	 * ``" Did you mean `x`?"`` for the nearest of the known names, empty when none is near enough; a name of the core
	 * is compared without its vendor as well, so that a name typed alone finds its preset.
	 * @param  list<string>  $known
	 */
	private static function suggest(string $name, array $known): string
	{
		$bare = array_map(self::abbreviate(...), $known);
		$hint = Helpers::getSuggestion($known, $name) ?? Helpers::getSuggestion($bare, $name);
		return $hint === null ? '' : " Did you mean `$hint`?";
	}


	/**
	 * What a name in a suppression comment stands for, as a report is told by: a decision or a section for itself, the
	 * class of a rule for its requirements, and a name of another tool for the requirements standing for it; empty when
	 * nothing does.
	 * @return list<string>
	 */
	public function expandSuppressedName(string $name): array
	{
		$catalogue = $this->getCatalogue();
		if ($catalogue->getDecisionsUnder($name) !== []) {
			return [$name];
		} elseif (class_exists($name) && is_subclass_of($name, Rule::class)) {
			$requirements = array_filter(Catalogue::collectDecisions($name), fn(Decision $decision) => !$decision->parameter);
			return array_values(array_map(fn(Decision $decision) => $decision->path, $requirements));
		}

		return array_values(array_filter(
			$this->translator->findPaths($name),
			fn(string $path) => $catalogue->find($path)?->parameter === false,
		));
	}


	/**
	 * The address of the page of what a report is told by: of a decision of the core, its page on dresscode.run, of one
	 * of a plugin or of the project, the page of the first rule declaring it.
	 */
	public function findUrl(string $name): ?string
	{
		$owner = $this->getCatalogue()->getRulesOf($name)[0] ?? null;
		return match (true) {
			$owner === null => null,
			!array_key_exists($owner, $this->sections) => self::DecisionUrl . $name,
			default => $this->findRuleUrl($owner),
		};
	}


	/**
	 * The catalogue of the registered rules: those of the core, of the plugins under their sections and of the project,
	 * in the order of the registration.
	 * @throws ConfigurationException
	 */
	public function getCatalogue(): Catalogue
	{
		if ($this->catalogue === null) {
			[$core, $plugins, $project] = $this->getRulesByOrigin();
			$this->catalogue = new Catalogue($core, $plugins, $project, $this->coreDecisions, $this->pluginDecisions, $this->pluginDependencies);
		}

		return $this->catalogue;
	}


	/** The name as it is written in a configuration or a comment: one of the core without its vendor. */
	public static function abbreviate(string $name): string
	{
		return str_starts_with($name, self::Vendor) ? substr($name, strlen(self::Vendor)) : $name;
	}


	/** Makes the name of a package whose plugin the project lets in known, so that `use` naming it names no preset. */
	public function registerPluginPackage(string $name): void
	{
		$this->pluginPackages[$name] = true;
	}


	public function isPluginPackage(string $name): bool
	{
		return isset($this->pluginPackages[$name]);
	}


	/** @throws ConfigurationException */
	public function registerPreset(string $name, string $file): void
	{
		$file = strtr($file, '\\', '/');
		$existing = $this->presets[$name] ?? null;
		if (!preg_match('~^[a-z0-9_.-]+/[a-zA-Z0-9_.-]+$~D', $name)) {
			throw new ConfigurationException("Preset name `$name` is not `vendor/name`.");
		} elseif ($existing !== null && $existing !== $file) {
			throw new ConfigurationException("Preset name `$name` is used by both `$existing` and `$file`.");
		} elseif (!is_file($file)) {
			throw new ConfigurationException("Preset `$name` names file `$file`, which does not exist.");
		}

		$this->presets[$name] = $file;
	}


	/**
	 * The name of the preset as it is registered, one of the core with its vendor.
	 * @throws ConfigurationException
	 */
	public function resolvePreset(string $preset): string
	{
		return $this->findPreset($preset)
			?? throw new ConfigurationException("Unknown preset `$preset`." . self::suggest($preset, array_keys($this->presets)));
	}


	/** The name of the preset as it is registered, one of the core with its vendor; null where no preset has the name. */
	public function findPreset(string $preset): ?string
	{
		return match (true) {
			isset($this->presets[$preset]) => $preset,
			isset($this->presets[self::Vendor . $preset]) => self::Vendor . $preset,
			default => null,
		};
	}


	/**
	 * The class of the rule, registered on the way, or the name of the preset, for a place that takes either.
	 * @throws ConfigurationException
	 */
	public function registerRuleOrResolvePreset(string $name): RuleOrPreset
	{
		if (class_exists($name) && is_subclass_of($name, Rule::class)) {
			$this->registerRule($name);
			return new RuleOrPreset(rule: $name);
		}

		return match (true) {
			isset($this->presets[$name]), isset($this->presets[self::Vendor . $name]) => new RuleOrPreset(preset: $this->resolvePreset($name)),
			default => throw $this->createUnknownNameException(
				$name,
				"Unknown decision, preset or rule `$name`.",
				self::suggest($name, array_keys($this->presets)),
			),
		};
	}
}
