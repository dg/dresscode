<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurableRule;
use DressCode\ConfigurationException;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rule;
use DressCode\RuleInfo;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;


/**
 * Composes the presets of a configuration with its own rules into the ordered list of rule instances:
 * parents first, a later mention overrides the whole entry, the order is that of the first mention.
 * @internal
 */
final class PresetResolver
{
	public function __construct(
		private readonly RuleRegistry $registry,
	) {
	}


	/**
	 * @return list<Rule>
	 * @throws ConfigurationException
	 */
	public function resolve(Config $config, PresetContext $context): array
	{
		$entries = [];
		$visited = [];
		foreach ($config->getPresets() as $preset) {
			$this->collect($this->registry->resolvePreset($preset), $context, $entries, $visited);
		}

		foreach ($config->getRules() as $rule => $value) {
			$entries[$this->registry->resolveRule($rule)] = $value;
		}

		$rules = [];
		foreach ($entries as $class => $value) {
			if ($value !== false) {
				$rules[] = $this->instantiate($class, $value);
			}
		}

		return $rules;
	}


	/**
	 * @param  class-string<Preset>  $class
	 * @param  array<class-string<Rule>, bool|array<string, mixed>|\Closure(): Rule>  $entries
	 * @param  array<class-string<Preset>, true>  $visited
	 */
	private function collect(string $class, PresetContext $context, array &$entries, array &$visited): void
	{
		if (isset($visited[$class])) {
			return;
		}

		$visited[$class] = true;
		$preset = new $class;
		foreach ($preset->getParents() as $parent) {
			$this->collect($this->registry->resolvePreset($parent), $context, $entries, $visited);
		}

		foreach ($preset->getRules($context) as $rule => $value) {
			try {
				$entries[$this->registry->resolveRule($rule)] = $value;
			} catch (ConfigurationException $e) {
				throw new ConfigurationException("{$e->getMessage()} (in preset " . PresetInfo::of($class)->name . ')', previous: $e);
			}
		}
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  true|array<string, mixed>|\Closure(): Rule  $value
	 */
	private function instantiate(string $class, bool|array|\Closure $value): Rule
	{
		$name = RuleInfo::of($class)->name;
		$rule = $value instanceof \Closure ? $value() : new $class;
		if (!$rule instanceof $class) {
			throw new ConfigurationException("The factory of rule $name returned " . $rule::class . " instead of $class.");
		}

		if ($rule instanceof ConfigurableRule) {
			$rule->configure(self::validateOptions($rule, $name, is_array($value) ? $value : []));
		} elseif (is_array($value)) {
			throw new ConfigurationException("Rule $name has no options.");
		}

		return $rule;
	}


	/**
	 * @param  array<string, mixed>  $options
	 * @return array<string, mixed>
	 */
	private static function validateOptions(ConfigurableRule $rule, string $name, array $options): array
	{
		try {
			$normalized = (new Processor)->process($rule::getOptionsSchema(), $options);
		} catch (ValidationException $e) {
			throw new ConfigurationException("Invalid options of rule $name: " . implode(' ', $e->getMessages()), previous: $e);
		}

		return (array) $normalized;
	}
}
