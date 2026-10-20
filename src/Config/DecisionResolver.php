<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Decision, DecisionKind, Domain, Rule, RuleInfo, Value, Values};
use DressCode\Domains\{Count, Flag, Map, Words};
use DressCode\Interop\Translator;
use Nette\Utils\Helpers;
use function count, is_array, is_bool, is_int, is_string, strlen;


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
	private const PresenceWords = ['required', 'forbidden', 'optional'];


	public function __construct(
		public Catalogue $catalogue,
		/** the versions of PHP the code is written for, as a Composer constraint */
		private string $phpTarget = Config::DefaultPhpVersion,
		/** the packages the project stands on, which decide whether a rule requiring one runs; null runs it whatever they are */
		private ?ProjectPackages $project = new ProjectPackages,
		/** whether the run has the types of the code */
		private bool $typesAnalyzed = false,
		/** whether the namespaces of the configuration are complete, which turns on their guard */
		private bool $certainNames = false,
		/** knows the names of the rules of other tools and the decisions that cover them */
		private Translator $translator = new Translator,
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
		$said = $cascaded = [];
		foreach ($layers as [$origin, $tree]) {
			foreach ($this->flatten($tree, '') as $path => [$value, $tombstone, $inherited]) {
				$said[$path][] = [$value->withOrigin($origin), $tombstone];
				$cascaded[$path] = $inherited;
			}
		}

		$resolved = [];
		foreach ($this->catalogue->getDecisions() as $path => $decision) {
			$merged = null;
			foreach ($said[$path] ?? [] as [$value, $tombstone]) {
				$merged = match (true) {
					$tombstone => $value,
					$merged !== null => $decision->domain->merge($merged, $value, $path),
					// the first list holding `...` stands on the default
					in_array(Domain::Spread, is_array($value->content) ? $value->content : [], true) => $decision->domain->merge(
						$decision->getDefault(),
						$value,
						$path,
					),
					default => $value,
				};
			}

			$merged ??= $decision->getDefault();
			$resolved[$path] = new ResolvedDecision(
				$decision,
				$this->catalogue->getRulesOf($path),
				$merged,
				array_column($said[$path] ?? [], 0),
				match (true) {
					$decision->kind === DecisionKind::Fact => $this->certainNames ? null : InactiveReason::NameResolution,
					$decision->isRequirement() && $merged->isKept() => InactiveReason::Keep,
					default => null,
				},
				$cascaded[$path] ?? false,
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
	 * Why the rule does not run whatever the values of its decisions: a target older than the rule needs, a package the
	 * project does not meet, or types the run does not have.
	 * @param  class-string<Rule>  $rule
	 */
	public function findRuleReason(string $rule): ?InactiveReason
	{
		$info = RuleInfo::fromRule($rule);
		$php = $info->requires['php'] ?? null;
		return match (true) {
			$php !== null && !Versions::isSubset($this->phpTarget, $php) => InactiveReason::Php,
			$this->project?->findUnmetRequirement($info->getRequiredPackages()) !== null => InactiveReason::Package,
			$info->typesRequired && !$this->typesAnalyzed => InactiveReason::Types,
			default => null,
		};
	}


	/**
	 * The values of a tree by the paths of their decisions, each with whether it replaces what the layers below said,
	 * the tombstone of a `keep` on a section or a structure and a map written with `!` after its key, and whether it was
	 * written on a group above the decision.
	 * @param  array<mixed>  $tree
	 * @return array<string, array{Value, bool, bool}>
	 * @throws ConfigurationException
	 */
	private function flatten(array $tree, string $prefix): array
	{
		// a kind of places goes first, so that a place the same map names wins over it
		$kinds = array_intersect_key($tree, Catalogue::PlaceKinds);
		$values = [];
		foreach ($kinds + $tree as $key => $raw) {
			$path = $prefix === '' ? (string) $key : "$prefix.$key";
			if (is_string($key) && str_contains($key, '.')) {
				throw new ConfigurationException("Key `$path` holds dots; write its links nested, each a key of its own.");
			}

			$replacing = is_string($key) && str_ends_with($key, '!');
			$path = $replacing ? substr($path, 0, -1) : $path;
			$decision = is_int($key) ? null : $this->catalogue->find($path);
			if ($replacing && (!$decision?->domain instanceof Map || !is_array($raw) || $raw === [])) {
				throw new ConfigurationException("Key `$path!` replaces a map of the layers below with the entries it writes, and `$path` is no such map or gives none; write `$path` without `!`, or `keep` to withdraw the map.");
			} elseif ($decision !== null) {
				$values[$path] = [$decision->accept($raw), $replacing, false];
				continue;
			}

			$under = is_int($key) ? [] : $this->catalogue->getDecisionsUnder($path);
			if ($under === [] && $prefix !== '' && isset($kinds[$key])) {
				$under = array_filter(
					$this->catalogue->getDecisionsUnder($prefix),
					fn(Decision $decision) => in_array(substr($decision->path, strlen($prefix) + 1), Catalogue::PlaceKinds[$key], true),
				);
			}

			if ($under === []) {
				$this->refuseKey($path, $prefix);
			} elseif ($raw === 'keep') {
				foreach ($under as $inner => $decision) {
					$values[$inner] = [$decision->takesKeep() ? Value::keep($decision->domain) : $decision->getDefault(), true, true];
				}
			} elseif ($raw === []) {
				throw new ConfigurationException("Key `$path` holds keys of its own, and an empty list or map says nothing of them; write the keys, or `keep`.");
			} elseif (is_array($raw) && !array_is_list($raw)) {
				$values = $this->flatten($raw, $path) + $values;
			} elseif ($prefix !== '' && (is_string($raw) || is_int($raw) || is_bool($raw) || is_array($raw))) {
				$values = $this->inherit($raw, $path, $under) + $values;
			} elseif ($prefix === '') {
				throw new ConfigurationException("Key `$path` is a section, which takes `keep` alone; write the values at its keys.");
			} else {
				throw new ConfigurationException("Key `$path` holds keys of its own; write a map of them or `keep`.");
			}
		}

		return $values;
	}


	/**
	 * The value written on a structure or a kind of places as the value of each decision it covers, `operatorPosition:
	 * lineStart`: a word, a count or a flag goes to the decisions of a closed vocabulary that take it, and a list to each
	 * of them as the words of it that decision takes, the others left out there; a text, a list of names, a map and an
	 * example are never given that way. A word no decision takes, one only parameters take among requirements, a word of
	 * presence a requirement does not take or given to a group of one thing, a list leaving a decision none, and `keep`
	 * or `...` in a list are errors.
	 * @param  string|int|bool|array<mixed>  $raw
	 * @param  non-empty-array<string, Decision>  $decisions
	 * @return array<string, array{Value, bool, bool}>
	 * @throws ConfigurationException
	 */
	private function inherit(string|int|bool|array $raw, string $path, array $decisions): array
	{
		$takes = function (Decision $decision, mixed $word): bool {
			try {
				$decision->accept($word);
				return true;
			} catch (ConfigurationException) {
				return false;
			}
		};
		$words = is_array($raw) ? $raw : [$raw];
		if (is_array($raw) && !array_is_list($raw)) {
			throw new ConfigurationException("Key `$path` holds keys of its own; write a map of them or `keep`.");
		} elseif (is_array($raw) && in_array('keep', $raw, true)) {
			throw new ConfigurationException("Key `$path` holds `keep` in a list; `keep` stands alone.");
		} elseif (is_array($raw) && in_array(Domain::Spread, $raw, true)) {
			throw new ConfigurationException("Key `$path` holds `...` in a list, which every decision under it would read as another list; write it at the decision.");
		}

		$presence = array_intersect($words, self::PresenceWords);
		if ($presence !== [] && in_array($path, Catalogue::QuestionGroups, true)) {
			throw new ConfigurationException('Key `' . $path . '` names one thing several decisions ask about, so `' . reset($presence) . '` would read as one about the thing itself; write it at the decisions, or `keep` at the key.');
		}

		$closed = array_filter(
			$decisions,
			fn(Decision $decision) => $decision->domain instanceof Words
				|| $decision->domain instanceof Count
				|| $decision->domain instanceof Flag,
		);
		$requirements = array_filter($closed, fn(Decision $decision) => $decision->isRequirement());
		foreach ($words as $word) {
			if (!array_any($closed, fn(Decision $decision) => $takes($decision, $word))) {
				$decision = reset($closed) ?: reset($decisions);
				$decision->accept($word); // refused, with what the domain expects
				throw new ConfigurationException("Key `$path` gives `$word`, which a group gives no decision under it; write it at the decision.");
			} elseif ($requirements !== [] && !array_any($requirements, fn(Decision $decision) => $takes($decision, $word))) {
				throw new ConfigurationException("Key `$path` gives `$word`, which only a parameter under it takes; write it at the parameter.");
			}
		}

		foreach ($presence as $word) {
			$refusing = array_find_key(
				array_filter($decisions, fn(Decision $decision) => $decision->isRequirement()),
				fn(Decision $decision) => !$takes($decision, $word),
			);
			if ($refusing !== null) {
				throw new ConfigurationException("Key `$path` gives `$word`, which `$refusing` does not take; a word of presence on a group holds for every decision under it, so write the decisions one by one.");
			}
		}

		$values = [];
		foreach ($closed as $inner => $decision) {
			$taken = array_values(array_filter($words, fn($word) => $takes($decision, $word)));
			if ($taken === [] && is_array($raw) && $decision->isRequirement()) {
				throw new ConfigurationException("Key `$path` leaves `$inner` none of its values; write a value for `$inner` of its own.");
			} elseif ($taken !== []) {
				$value = match (true) {
					!is_array($raw) => $raw,
					count($taken) === 1 && !$takes($decision, $taken) => $taken[0], // a decision of one word
					default => $taken,
				};
				$values[$inner] = [$decision->accept($value), false, true];
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

		$covered = $prefix === '' ? $this->translator->findPaths($path) : [];
		$hint = Helpers::getSuggestion(array_keys($known), $path);
		throw new ConfigurationException("Key `$path` is unknown" . match (true) {
			$covered !== [] => '. It is the name of a rule of another tool, covered by `'
				. implode('` and `', $covered)
				. '`; `dresscode migrate config` translates a configuration of another tool.',
			$hint !== null => "; write `$hint`.",
			default => '.',
		}, docs: $covered !== [] ? 'migration#migrate-config' : null);
	}
}
