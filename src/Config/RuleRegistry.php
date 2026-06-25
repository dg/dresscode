<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Preset, PresetInfo, Rule, RuleInfo, Rules};
use Nette\Utils\Helpers;
use function strlen;


/**
 * Rule and preset classes known to a run, by name or class; a name may belong to one class only.
 * A name without a vendor is the built-in one of that name, so 'per' is 'dresscode/per'.
 * @internal
 */
final class RuleRegistry
{
	private const Vendor = 'dresscode/';

	private const BuiltinRules = [
		Rules\Arrays\ShortArraySyntaxRule::class,
		Rules\Comments\NoEmptyCommentRule::class,
		Rules\Comments\NoHashCommentRule::class,
		Rules\ControlFlow\ElseifKeywordRule::class,
		Rules\ControlFlow\NoEmptyStatementRule::class,
		Rules\Files\NoBomRule::class,
		Rules\Files\NoInvisibleCharactersRule::class,
		Rules\Files\FullOpeningTagRule::class,
		Rules\Files\LineEndingRule::class,
		Rules\Files\NoClosingTagRule::class,
		Rules\Files\StrictTypesRequiredRule::class,
		Rules\Literals\ConstantCasingRule::class,
		Rules\Literals\KeywordCasingRule::class,
		Rules\Expressions\NotEqualsOperatorRule::class,
		Rules\PhpDoc\NoEmptyPhpDocRule::class,
		Rules\Literals\MagicConstantCasingRule::class,
		Rules\Variables\NoGlobalKeywordRule::class,
		Rules\Whitespace\ParenthesesSpacingRule::class,
		Rules\Files\NoTrailingWhitespaceRule::class,
		Rules\Files\EofNewlineRule::class,
	];

	/** @var array<string, class-string<Rule>>  name → class */
	private array $rules = [];

	/** @var array<string, class-string<Preset>>  name → class */
	private array $presets = [];


	public function __construct()
	{
		foreach (self::BuiltinRules as $class) {
			$this->registerRule($class);
		}
	}


	/**
	 * Returns the name of the rule.
	 * @param  class-string<Rule>  $class
	 * @throws ConfigurationException  when the name belongs to another rule
	 */
	public function registerRule(string $class): string
	{
		if (!is_subclass_of($class, Rule::class)) {
			throw new ConfigurationException("Class $class is not a rule.");
		}

		$info = RuleInfo::of($class);
		$existing = $this->rules[$info->name] ?? null;
		if ($existing !== null && $existing !== $class) {
			throw new ConfigurationException("Rule name '$info->name' is used by both $existing and $class.");
		}

		$this->rules[$info->name] = $class;
		return $info->name;
	}


	/**
	 * Class of the rule given by name or class; a class is registered on the way. A name of another tool
	 * is not a name here: it is translated together with its options by `dresscode import`.
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

		throw new ConfigurationException("Unknown rule '$rule'." . self::suggest($rule, array_keys($this->rules)));
	}


	/**
	 * " Did you mean 'x'?" for the nearest of the known names, empty when none is near enough; the name
	 * is compared without its vendor as well, so that a slug typed alone finds its rule.
	 * @param  list<string>  $known
	 */
	private static function suggest(string $name, array $known): string
	{
		$bare = array_map(fn(string $item) => substr($item, strlen(self::Vendor)), $known);
		$hint = Helpers::getSuggestion($known, $name) ?? Helpers::getSuggestion($bare, $name);
		return $hint === null ? '' : " Did you mean '$hint'?";
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


	/** @return array<string, class-string<Rule>>  name → class */
	public function getRules(): array
	{
		return $this->rules;
	}


	/**
	 * Returns the name of the preset.
	 * @param  class-string<Preset>  $class
	 * @throws ConfigurationException
	 */
	public function registerPreset(string $class): string
	{
		if (!is_subclass_of($class, Preset::class)) {
			throw new ConfigurationException("Class $class is not a preset.");
		}

		$name = PresetInfo::of($class)->name;
		$existing = $this->presets[$name] ?? null;
		if ($existing !== null && $existing !== $class) {
			throw new ConfigurationException("Preset name '$name' is used by both $existing and $class.");
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
			"Unknown preset '$preset'." . self::suggest($preset, array_keys($this->presets)),
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
			$rule !== null && $preset !== null => throw new ConfigurationException("'$name' names both a rule and a preset; name it by its class."),
			$rule !== null => $this->resolveRule($rule),
			$preset !== null => $this->resolvePreset($preset),
			default => throw new ConfigurationException(
				"Unknown rule or preset '$name'." . self::suggest($name, [...array_keys($this->rules), ...array_keys($this->presets)]),
			),
		};
	}


	/** @return array<string, class-string<Preset>>  name → class */
	public function getPresets(): array
	{
		return $this->presets;
	}
}
