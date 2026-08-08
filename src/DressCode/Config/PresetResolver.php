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
use Nette\Schema\Elements\ArrayType;
use Nette\Schema\Elements\Structure;
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
		$rules = [];
		foreach ($this->collectEntries($config, $context) as $class => $value) {
			$rules[] = self::createRule($class, $value);
		}

		return $rules;
	}


	/**
	 * The effective rules as data: name → options as configured, [] for none, 'factory' for a rule built by
	 * a closure; what a result cache keys on.
	 * @return array<string, array<string, mixed>|string>
	 * @throws ConfigurationException
	 */
	public function describe(Config $config, PresetContext $context): array
	{
		$description = [];
		foreach ($this->collectEntries($config, $context) as $class => $value) {
			$description[RuleInfo::of($class)->name] = match (true) {
				$value instanceof \Closure => 'factory',
				$value === true => [],
				default => $value,
			};
		}

		return $description;
	}


	/**
	 * Rules of the presets and the configuration, without the disabled ones.
	 * @return array<class-string<Rule>, true|array<string, mixed>|\Closure(): Rule>
	 * @throws ConfigurationException
	 */
	private function collectEntries(Config $config, PresetContext $context): array
	{
		$entries = [];
		foreach ($this->listPresets($config) as $class) {
			foreach ((new $class)->getRules($context) as $rule => $value) {
				try {
					$entries[$this->registry->resolveRule($rule)] = $value;
				} catch (ConfigurationException $e) {
					throw new ConfigurationException("{$e->getMessage()} (in preset " . PresetInfo::of($class)->name . ')', previous: $e);
				}
			}
		}

		foreach ($config->getRules() as $rule => $value) {
			$entries[$this->registry->resolveRule($rule)] = $value;
		}

		return array_filter($entries, fn($value) => $value !== false);
	}


	/**
	 * Indentation unit and line ending of a run: the configuration wins, then the last preset declaring
	 * them, then a tab and the prevailing line ending of each file.
	 * @return array{string, "\n"|"\r\n"|'auto'}
	 * @throws ConfigurationException
	 */
	public function resolveStyle(Config $config): array
	{
		$indent = $eol = null;
		foreach ($this->listPresets($config) as $class) {
			$info = PresetInfo::of($class);
			$indent = $info->indent ?? $indent;
			$eol = $info->eol ?? $eol;
		}

		$eol = $config->getEol() ?? $eol ?? 'auto';
		if (!in_array($eol, ["\n", "\r\n", 'auto'], strict: true)) {
			throw new ConfigurationException('The line ending must be "\n", "\r\n" or \'auto\'.');
		}

		return [$config->getIndent() ?? $indent ?? "\t", $eol];
	}


	/**
	 * Presets of the configuration with their parents, parents first, each once.
	 * @return list<class-string<Preset>>
	 * @throws ConfigurationException
	 */
	private function listPresets(Config $config): array
	{
		$list = $visited = [];
		foreach ($config->getPresets() as $preset) {
			$this->collectPreset($this->registry->resolvePreset($preset), $list, $visited);
		}

		return $list;
	}


	/**
	 * @param  class-string<Preset>  $class
	 * @param  list<class-string<Preset>>  $list
	 * @param  array<class-string<Preset>, true>  $visited
	 */
	private function collectPreset(string $class, array &$list, array &$visited): void
	{
		if (isset($visited[$class])) {
			return;
		}

		$visited[$class] = true;
		foreach ((new $class)->getParents() as $parent) {
			$this->collectPreset($this->registry->resolvePreset($parent), $list, $visited);
		}

		$list[] = $class;
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  true|array<string, mixed>|\Closure(): Rule  $value
	 */
	public static function createRule(string $class, bool|array|\Closure $value = true): Rule
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
		$schema = $rule::getOptionsSchema();
		if ($schema instanceof Structure) { // an option given replaces its default whole, lists are not merged
			foreach ($schema->getShape() as $item) {
				if ($item instanceof ArrayType) {
					$item->mergeDefaults(false);
				}
			}
		}

		try {
			$normalized = (new Processor)->process($schema, $options);
		} catch (ValidationException $e) {
			throw new ConfigurationException("Invalid options of rule $name: " . implode(' ', $e->getMessages()), previous: $e);
		}

		return (array) $normalized;
	}
}
