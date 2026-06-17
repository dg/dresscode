<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Rule, RuleInfo, Value, Values};
use Nette\Utils\Helpers;
use function is_array, is_int, is_string, strlen;


/**
 * Lays the layers of the decisions one over another: each layer writes a tree of sections, whose every path is
 * checked against the catalogue and normalized by the decision it reaches, and the values of one path merge by
 * the law of its domain. `keep` on a section or a structure is a tombstone for every key under it, which a later
 * layer brings back one key at a time: a requirement becomes `keep` and a parameter, which takes no `keep`, its
 * default, so that nothing a layer below said comes back from under the tombstone.
 * @internal
 */
final readonly class DecisionResolver
{
	public function __construct(
		public Catalogue $catalogue,
		/** the versions of PHP the code is written for, as a Composer constraint */
		private string $phpTarget = Config::DefaultPhpVersion,
		/** whether the namespaces of the configuration are complete, which turns on their guard */
		private bool $certainNames = false,
	) {
	}


	/**
	 * Every decision of the catalogue with what the layers came to.
	 * @param  list<array{Layer, array<string, mixed>}>  $layers  who says it and the tree of sections it writes, from the bottom up
	 * @return array<string, ResolvedDecision>
	 * @throws ConfigurationException
	 */
	public function resolve(array $layers): array
	{
		$said = [];
		foreach ($layers as [$origin, $tree]) {
			foreach ($this->flatten($tree, '') as $path => [$value, $tombstone]) {
				$said[$path][] = [$value->withOrigin($origin), $tombstone];
			}
		}

		$resolved = [];
		foreach ($this->catalogue->getDecisions() as $path => $decision) {
			$merged = null;
			foreach ($said[$path] ?? [] as [$value, $tombstone]) {
				$merged = $merged === null || $tombstone ? $value : $decision->domain->merge($merged, $value);
			}

			$merged ??= $decision->getDefault();
			$resolved[$path] = new ResolvedDecision(
				$decision,
				$this->catalogue->getRulesOf($path),
				$merged,
				array_column($said[$path] ?? [], 0),
				match (true) {
					$decision->fact => $this->certainNames ? null : InactiveReason::NameResolution,
					$decision->isRequirement() && $merged->isKept() => InactiveReason::Keep,
					default => null,
				},
			);
		}

		foreach ($resolved as $path => $decision) {
			$reasons = array_map($this->findRuleReason(...), $decision->rules);
			if ($reasons !== [] && !in_array(null, $reasons, true)) {
				$resolved[$path] = $decision->withInactive($reasons[0]);
			}
		}

		return $resolved;
	}


	/**
	 * The values of one layer by the paths of their decisions, checked against the catalogue; for the caller to
	 * tell which layer an error is in.
	 * @param  array<string, mixed>  $tree
	 * @throws ConfigurationException
	 */
	public function checkLayer(array $tree): void
	{
		$this->flatten($tree, '');
	}


	/**
	 * The values a rule reads, with the mask of the run.
	 * @param  array<string, ResolvedDecision>  $resolved
	 * @param  ?list<string>  $selection  the paths and prefixes the run is narrowed to; null for all
	 */
	public function createValues(array $resolved, ?array $selection = null): Values
	{
		return new Values(
			$this->catalogue->getDecisions(),
			array_map(fn(ResolvedDecision $decision) => $decision->value, $resolved),
			$selection,
		);
	}


	/**
	 * Why the rule does not run whatever the values of its decisions: a target older than the rule needs.
	 * @param  class-string<Rule>  $rule
	 */
	public function findRuleReason(string $rule): ?InactiveReason
	{
		$php = RuleInfo::of($rule)->requires['php'] ?? null;
		return $php !== null && !Versions::isSubset($this->phpTarget, $php) ? InactiveReason::Php : null;
	}


	/**
	 * The values of a tree by the paths of their decisions, each with whether it is the tombstone of a `keep` on
	 * a section or a structure, which the values below it do not merge with.
	 * @param  array<mixed>  $tree
	 * @return array<string, array{Value, bool}>
	 * @throws ConfigurationException
	 */
	private function flatten(array $tree, string $prefix): array
	{
		$values = [];
		foreach ($tree as $key => $raw) {
			$path = $prefix === '' ? (string) $key : "$prefix.$key";
			$decision = is_int($key) ? null : $this->catalogue->find($path);
			if ($decision !== null) {
				$values[$path] = [$decision->accept($raw), false];
				continue;
			}

			$under = is_int($key) ? [] : $this->catalogue->getDecisionsUnder($path);
			if ($under === []) {
				$this->refuseKey($path, $prefix);
			} elseif ($raw === 'keep') {
				foreach ($under as $inner => $decision) {
					$values[$inner] = [$decision->isRequirement() ? Value::keep($decision->domain) : $decision->getDefault(), true];
				}
			} elseif (is_array($raw) && ($raw === [] || !array_is_list($raw))) {
				$values = $this->flatten($raw, $path) + $values;
			} elseif ($prefix !== '' && is_string($raw)) {
				// a word of a structure is the word of each of its requirements, `operatorPosition: lineStart`
				foreach ($under as $inner => $decision) {
					if ($decision->isRequirement()) {
						$values[$inner] = [$decision->accept($raw), false];
					}
				}
			} else {
				throw new ConfigurationException("Key `$path` holds keys of its own; write a map of them or `keep`.");
			}
		}

		return $values;
	}


	/** @throws ConfigurationException */
	private function refuseKey(string $path, string $prefix): never
	{
		$known = [];
		foreach (array_keys($this->catalogue->getDecisions()) as $decision) {
			if ($prefix === '' || str_starts_with($decision, "$prefix.")) {
				$rest = $prefix === '' ? $decision : substr($decision, strlen($prefix) + 1);
				$known[($prefix === '' ? '' : "$prefix.") . explode('.', $rest, 2)[0]] = true;
			}
		}

		$hint = Helpers::getSuggestion(array_keys($known), $path);
		throw new ConfigurationException("Key `$path` is unknown" . ($hint !== null ? "; write `$hint`." : '.'));
	}
}
