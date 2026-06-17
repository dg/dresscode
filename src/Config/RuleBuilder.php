<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Decision, Rule, Values};
use function array_key_exists, is_array;


/**
 * Makes the instances of the rules a resolution runs, each configured with the values of the decisions.
 * @internal
 */
final class RuleBuilder
{
	/**
	 * The rule configured with the values of its decisions, a requirement not given taking the one value its domain
	 * has, if any. For a test and a tool that runs one rule.
	 * @param  class-string<Rule>  $class
	 * @param  array<string, mixed>|\Closure(): Rule  $values  path => value, or a factory
	 * @throws ConfigurationException
	 */
	public static function createRule(string $class, array|\Closure $values = []): Rule
	{
		$rule = $values instanceof \Closure ? self::invokeFactory($class, $values) : new $class;
		$rule->configure(self::resolveValues([$class], is_array($values) ? $values : []));
		return $rule;
	}


	/**
	 * The rules configured with one set of values, as one run configures them, each reading the values of them all.
	 * For a test running a few rules, with the values of `resolveValues()`.
	 * @param  list<class-string<Rule>>  $classes
	 * @return list<Rule>
	 */
	public static function createRules(array $classes, Values $values): array
	{
		return array_map(function (string $class) use ($values): Rule {
			$rule = new $class;
			$rule->configure($values);
			return $rule;
		}, $classes);
	}


	/**
	 * The values the rules are configured with, those given over the defaults of the catalogue of the rules, a
	 * requirement not given taking the one value its domain has, if any.
	 * @param  list<class-string<Rule>>  $classes
	 * @param  array<string, mixed>  $given  path => value
	 * @throws ConfigurationException
	 */
	public static function resolveValues(array $classes, array $given = []): Values
	{
		$catalogue = Catalogue::fromRules($classes);
		foreach ($classes as $class) {
			foreach ($catalogue->getDecisionsOf($class) as $decision) {
				$sole = $decision->isRequirement() ? $decision->domain->findSoleValue() : null;
				if ($sole !== null && !array_key_exists($decision->path, $given)) {
					$given[$decision->path] = $sole;
				}
			}
		}

		$resolver = new DecisionResolver($catalogue);
		return $resolver->createValues($resolver->resolve([[new Layer(LayerKind::Caller), $given]]));
	}


	/**
	 * Instances of the rules that run, in the order they run, each configured with the values of the resolution.
	 * @return list<Rule>
	 * @throws ConfigurationException
	 */
	public static function buildRules(ResolvedConfig $resolved): array
	{
		$rules = [];
		foreach ($resolved->getActiveRules() as $active) {
			$rule = $active->factory === null ? new ($active->class) : self::invokeFactory($active->class, $active->factory);
			$rule->configure($resolved->values);
			$rules[] = $rule;
		}

		return $rules;
	}


	/**
	 * Instances of the rules a requirement or a fact of which takes effect, in the order of the registration, each
	 * configured with the values; a rule whose decisions are all `keep`, or that cannot run here, is not built.
	 * @param  array<string, ResolvedDecision>  $resolved
	 * @return list<Rule>
	 */
	public static function buildFromDecisions(DecisionResolver $resolver, array $resolved, Values $values): array
	{
		$rules = [];
		foreach ($resolver->catalogue->getRuleOrder() as $class) {
			$decisions = $resolver->catalogue->getDecisionsOf($class);
			if (
				$resolver->findRuleReason($class) === null
				&& array_any($decisions, fn(Decision $decision) => !$decision->parameter && $resolved[$decision->path]->inactive === null)
			) {
				$rule = new $class;
				$rule->configure($values);
				$rules[] = $rule;
			}
		}

		return $rules;
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  \Closure(): Rule  $factory
	 * @throws ConfigurationException
	 */
	private static function invokeFactory(string $class, \Closure $factory): Rule
	{
		$rule = $factory();
		return $rule instanceof $class
			? $rule
			: throw new ConfigurationException("The factory of rule `$class` returned `" . get_debug_type($rule) . "` instead of `$class`.");
	}
}
