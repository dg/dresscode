<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurableRule, ConfigurationException, Rule, RuleInfo};
use Nette\Schema\Elements\{ArrayType, Structure};
use Nette\Schema\{Helpers, Processor, ValidationException};
use function count, is_array, is_int, is_string;


/**
 * Makes the instance of a rule its resolution describes: the layers of its options processed through its schema, and
 * the rule configured with what they come to.
 * @internal
 */
final class RuleBuilder
{
	/**
	 * The rule configured with the value, as a configuration would give it; for a test and a tool that runs one rule.
	 * @param  class-string<Rule>  $class
	 * @param  true|string|int|array<string, mixed>|\Closure(): Rule  $value
	 * @throws ConfigurationException
	 */
	public static function createRule(string $class, bool|string|int|array|\Closure $value = true): Rule
	{
		$layers = [['the caller', $value]];
		return self::buildRule(new ResolvedRule(
			RuleInfo::of($class)->name,
			$class,
			self::processOptions($class, $layers)[0],
			$layers,
			factory: $value instanceof \Closure ? $value : null,
		));
	}


	/**
	 * Instances of the rules that run, in the order they run.
	 * @return list<Rule>
	 * @throws ConfigurationException
	 */
	public static function buildRules(ResolvedConfig $resolved): array
	{
		return array_map(self::buildRule(...), $resolved->getActiveRules());
	}


	/** @throws ConfigurationException */
	private static function buildRule(ResolvedRule $resolved): Rule
	{
		$class = $resolved->class;
		$rule = $resolved->factory === null ? new $class : ($resolved->factory)();
		if (!$rule instanceof $class) {
			throw new ConfigurationException("The factory of rule `$resolved->name` returned `" . $rule::class . "` instead of `$class`.");
		}

		if ($rule instanceof ConfigurableRule) {
			$rule->configure($resolved->options);
		} elseif (is_array($resolved->layers[count($resolved->layers) - 1][1] ?? null)) {
			throw new ConfigurationException("Rule `$resolved->name` has no options.");
		}

		return $rule;
	}


	/**
	 * The options the layers give the rule, processed through its schema, and what the schema warns about them, each
	 * a sentence of its own, such as that its options decide nothing or that one of them is deprecated.
	 * @param  class-string<Rule>  $class
	 * @param  list<array{string, mixed}>  $layers  who says it and what
	 * @return array{array<string, mixed>, list<string>}
	 * @throws ConfigurationException
	 */
	public static function processOptions(string $class, array $layers): array
	{
		$info = RuleInfo::of($class);
		return self::validateOptions($class, $info->name, self::stackLayers($layers, $info), self::describeSources($layers));
	}


	/**
	 * The layers a rule ends up with, as the schema takes them: turning the rule off drops everything said
	 * before it, so a map written after it starts from the defaults of the schema again.
	 * @param  list<array{string, mixed}>  $layers
	 * @return list<array<string, mixed>>
	 * @throws ConfigurationException
	 */
	private static function stackLayers(array $layers, RuleInfo $info): array
	{
		$stack = [];
		foreach ($layers as [$source, $value]) {
			if ($value === false) {
				$stack = [];
			} elseif (is_array($value)) {
				$stack[] = self::markLists($value, top: true);
			} elseif (is_string($value) || is_int($value)) {
				$stack[] = [self::resolveDecision($info, $source) => $value];
			} else {
				$stack[] = [];
			}
		}

		return $stack;
	}


	/**
	 * The option a bare value written for the rule fills. A rule that is more than one decision has none,
	 * and then the value has nowhere to go.
	 * @throws ConfigurationException
	 */
	private static function resolveDecision(RuleInfo $info, string $source): string
	{
		return $info->decision ?? throw new ConfigurationException(
			"Rule `$info->name` takes no bare value, which " . ConfigResolver::formatLayer($source) . ' gives it; write the options it has.',
		);
	}


	/**
	 * A map merges with the layer below it key by key, a list replaces it whole; the marker is how every
	 * `merge()` of nette/schema is told the second, and without it a list of a preset and a list of the
	 * project would be appended to one another.
	 */
	private static function markLists(mixed $value, bool $top = false): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		foreach ($value as $key => $item) {
			$value[$key] = self::markLists($item);
		}

		if (!$top && array_is_list($value)) {
			$value[Helpers::PreventMerging] = true;
		}

		return $value;
	}


	/**
	 * Who set the options of a rule, for an error message that has to send the reader somewhere.
	 * @param list<array{string, mixed}> $layers
	 */
	private static function describeSources(array $layers): string
	{
		$sources = [];
		foreach ($layers as [$source, $value]) {
			if (is_array($value)) {
				$sources[ConfigResolver::formatLayer($source)] = true;
			}
		}

		return implode(' and ', array_keys($sources));
	}


	/**
	 * The options a rule ends up with, the layers processed through its schema, and what the schema warns about them.
	 * @param  class-string<Rule>  $class
	 * @param  list<array<string, mixed>>  $layers
	 * @param  string  $sources  who set them, for an error message
	 * @return array{array<string, mixed>, list<string>}
	 */
	private static function validateOptions(string $class, string $name, array $layers, string $sources = ''): array
	{
		if (!is_subclass_of($class, ConfigurableRule::class)) {
			return [[], []];
		}

		$schema = $class::getOptionsSchema();
		if ($schema instanceof Structure) { // a list given replaces its default instead of extending it
			foreach ($schema->getShape() as $item) {
				if ($item instanceof ArrayType) {
					$item->mergeDefaults(false);
				}
			}
		}

		$processor = new Processor;
		try {
			$normalized = $processor->processMultiple($schema, $layers ?: [[]]);
		} catch (ValidationException $e) {
			throw new ConfigurationException(
				"Invalid options of rule `$name`" . ($sources === '' ? '' : " set by $sources") . ': '
				. implode(' ', $e->getMessages()),
				previous: $e,
			);
		}

		return [(array) $normalized, $processor->getWarnings()];
	}
}
