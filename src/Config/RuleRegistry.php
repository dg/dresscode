<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Preset, PresetInfo, Rule, RuleInfo};
use Nette\Utils\Helpers;
use function strlen;


/**
 * Rule and preset classes known to a run, by name or class; a name may belong to one class only.
 * A name without a vendor is the built-in one of that name, so `'perCs'` is `'dresscode/perCs'`.
 * @internal
 */
final class RuleRegistry
{
	private const Vendor = 'dresscode/';

	/** @var array<string, class-string<Rule>>  name => class */
	public private(set) array $rules = [];

	/** @var array<string, class-string<Preset>>  name => class */
	public private(set) array $presets = [];

	/** @var array<string, string>  name => url */
	private array $urls = [];


	public function __construct()
	{
		$builtin = (new BuiltinPlugin)->getManifest();
		foreach ($builtin->rules as $class) {
			// known by the name its class spells, so that a run loads only the rules it runs
			$name = self::nameOf($class);
			$this->rules[$name] = $class;
			if ($builtin->ruleUrl !== null) {
				$this->urls[$name] = str_replace('{slug}', substr($name, strlen(self::Vendor)), $builtin->ruleUrl);
			}
		}

		foreach ($builtin->presets as $class) {
			$this->registerPreset($class);
		}
	}


	/** The name of a built-in rule: its class without the suffix, the first letter in lower case. */
	private static function nameOf(string $class): string
	{
		return self::Vendor . lcfirst(substr($class, strrpos($class, '\\') + 1, -strlen('Rule')));
	}


	/**
	 * Returns the name of the rule.
	 * @param  class-string<Rule>  $class
	 * @throws ConfigurationException  when the name belongs to another rule
	 */
	public function registerRule(string $class, ?string $url = null): string
	{
		if (!is_subclass_of($class, Rule::class)) {
			throw new ConfigurationException("Class `$class` is not a rule.");
		}

		$info = RuleInfo::of($class);
		$existing = $this->rules[$info->name] ?? null;
		if ($existing !== null && $existing !== $class) {
			throw new ConfigurationException("Rule name `$info->name` is used by both `$existing` and `$class`.");
		}

		$this->rules[$info->name] = $class;
		if ($url !== null) {
			$this->urls[$info->name] = str_replace('{slug}', substr($info->name, strpos($info->name, '/') + 1), $url);
		}

		return $info->name;
	}


	public function getRuleUrl(string $name): ?string
	{
		return $this->urls[$name] ?? null;
	}


	/**
	 * Class of the rule given by name or class; a class is registered on the way.
	 * @return class-string<Rule>
	 * @throws ConfigurationException
	 */
	public function resolveRule(string $rule): string
	{
		if (class_exists($rule)) {
			/** @var class-string<Rule> $rule */
			$this->registerRule($rule);
			return $rule;
		}

		$class = $this->rules[$rule] ?? $this->rules[self::Vendor . $rule] ?? null;
		if ($class !== null) {
			return $class;
		}

		throw new ConfigurationException("Unknown rule `$rule`." . self::suggest($rule, array_keys($this->rules)));
	}


	/**
	 * ``" Did you mean `x`?"`` for the nearest of the known names, empty when none is near enough; the name
	 * is compared without its vendor as well, so that a slug typed alone finds its rule.
	 * @param  list<string>  $known
	 */
	private static function suggest(string $name, array $known): string
	{
		$bare = array_map(fn(string $item) => substr($item, strlen(self::Vendor)), $known);
		$hint = Helpers::getSuggestion($known, $name) ?? Helpers::getSuggestion($bare, $name);
		return $hint === null ? '' : " Did you mean `$hint`?";
	}


	/**
	 * Rules a name in a suppression comment stands for: its own; empty when nothing does.
	 * @return list<string>
	 */
	public function resolveNames(string $rule): array
	{
		return match (true) {
			isset($this->rules[$rule]) => [$rule],
			isset($this->rules[self::Vendor . $rule]) => [self::Vendor . $rule],
			default => [],
		};
	}


	/** The name as it is written in a configuration or a comment: a built-in one without its vendor. */
	public static function abbreviate(string $name): string
	{
		return str_starts_with($name, self::Vendor) ? substr($name, strlen(self::Vendor)) : $name;
	}


	/**
	 * Returns the name of the preset.
	 * @param  class-string<Preset>  $class
	 * @throws ConfigurationException
	 */
	public function registerPreset(string $class): string
	{
		if (!is_subclass_of($class, Preset::class)) {
			throw new ConfigurationException("Class `$class` is not a preset.");
		}

		$name = PresetInfo::of($class)->name;
		$existing = $this->presets[$name] ?? null;
		if ($existing !== null && $existing !== $class) {
			throw new ConfigurationException("Preset name `$name` is used by both `$existing` and `$class`.");
		}

		$this->presets[$name] = $class;
		return $name;
	}


	/**
	 * @return class-string<Preset>
	 * @throws ConfigurationException
	 */
	public function resolvePreset(string $preset): string
	{
		if (class_exists($preset)) {
			/** @var class-string<Preset> $preset */
			$this->registerPreset($preset);
			return $preset;
		}

		$class = $this->presets[$preset] ?? $this->presets[self::Vendor . $preset] ?? null;
		return $class ?? throw new ConfigurationException(
			"Unknown preset `$preset`." . self::suggest($preset, array_keys($this->presets)),
		);
	}


	/**
	 * Class of the rule or of the preset given by name or class, for a place that takes either; a name that
	 * belongs to both is an ambiguity, not a preference.
	 * @return class-string<Rule>|class-string<Preset>
	 * @throws ConfigurationException
	 */
	public function resolveRuleOrPreset(string $name): string
	{
		$isClass = class_exists($name);
		$rule = $isClass
			? (is_subclass_of($name, Rule::class) ? $name : null)
			: $this->rules[$name] ?? $this->rules[self::Vendor . $name] ?? null;
		$preset = $isClass
			? (is_subclass_of($name, Preset::class) ? $name : null)
			: $this->presets[$name] ?? $this->presets[self::Vendor . $name] ?? null;
		return match (true) {
			$rule !== null && $preset !== null => throw new ConfigurationException("`$name` names both a rule and a preset; name it by its class."),
			$rule !== null => $this->resolveRule($rule),
			$preset !== null => $this->resolvePreset($preset),
			default => throw new ConfigurationException(
				"Unknown rule or preset `$name`." . self::suggest($name, [...array_keys($this->rules), ...array_keys($this->presets)]),
			),
		};
	}
}
