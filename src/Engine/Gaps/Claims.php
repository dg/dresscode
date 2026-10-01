<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine\Gaps;

use DressCode\{Claim, ConfigurationException, Gap, GapRule, Rule};
use DressCode\Engine\Profiler;
use PhpSyntax\{LayoutData, Node};
use PhpSyntax\Nodes\{PlainNodeList, SeparatedNodeList};
use function sprintf, strlen;


/**
 * The claims of the gap rules of a configuration, checked once and looked up by the class and the slot of a node;
 * the resolver of each traversal reads them.
 * @internal
 */
final class Claims
{
	/** @var array<string, list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>>  `'Class.slot'` or `'*.slot'` => the claims, several where they claim different components or decide by a closure */
	private array $before = [];

	/** @var array<string, list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>> */
	private array $after = [];

	/** @var array<string, array<string, list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>>>  class => slot => the claims before it, of the class then of `'*'` */
	private array $beforeOf = [];

	/** @var array<string, array<string, list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>>> */
	private array $afterOf = [];

	/** @var array<string, list<string>>  class => its slots somebody claims a side of */
	private array $claimedSlots = [];


	/**
	 * @param  list<Rule>  $rules
	 * @throws ConfigurationException when a claim names a slot the tree has not, or when two rules claim
	 *         the same component of the same side of the same slot
	 */
	public function __construct(
		array $rules,
		private readonly ?Profiler $profiler = null,
	) {
		foreach ($rules as $rule) {
			if (!$rule instanceof GapRule) {
				continue;
			}

			foreach ($rule->getClaims() as $class => $slots) {
				$class = $class === '*' ? '*' : ltrim($class, '\\');
				foreach ($slots as $slot => [$before, $after]) {
					self::checkTarget($class, $slot, $rule);
					$key = "$class.$slot";
					$this->claim($this->before, 'before', $key, $rule, $before);
					$this->claim($this->after, 'after', $key, $rule, $after);
				}
			}
		}
	}


	/**
	 * Checks that the claim names something the tree has: a node class and one of its slots, `*` for every
	 * class, `:item` or `:separator` for a slot holding a list, and `*.*:item` or `*.*:separator` for every
	 * list. A slot renamed in the tree would otherwise turn the rule off without a word, the gaps of that slot
	 * simply never reaching it.
	 * @throws ConfigurationException
	 */
	private static function checkTarget(string $class, string $slot, Rule $rule): void
	{
		$name = $slot;
		$part = null;
		foreach ([':item', ':separator'] as $suffix) {
			if (str_ends_with($slot, $suffix)) {
				$name = substr($slot, 0, -strlen($suffix));
				$part = $suffix;
				break;
			}
		}

		if ($class !== '*' && !isset(LayoutData::Roles[$class])) {
			self::refuse($rule, $class, $slot, "`$class` is not a node class");
		}

		if ($name === '*') {
			if ($class !== '*' || $part === null) {
				self::refuse($rule, $class, $slot, 'every slot is claimed only as `*.*:item` or `*.*:separator`');
			}

			return; // the items or separators of every list, so there is no name to look up
		}

		$owners = self::findOwners($class, $name);
		if ($owners === []) {
			self::refuse($rule, $class, $slot, $class === '*' ? "no node has a slot `$name`" : "it has no slot `$name`");
		} elseif ($part !== null && !self::holdsList($owners, $name, $part)) {
			self::refuse($rule, $class, $slot, 'the slot holds no list to have ' . ($part === ':item' ? 'items' : 'separators'));
		}
	}


	private static function refuse(Rule $rule, string $class, string $slot, string $what): never
	{
		throw new ConfigurationException(sprintf(
			'Rule `%s` claims the whitespace of `%s.%s`, but %s.',
			$rule::class,
			$class,
			$slot,
			$what,
		));
	}


	/**
	 * The node classes the claim reaches that have the slot: the one named, or every one for `'*'`.
	 * @return list<class-string<Node>>
	 */
	private static function findOwners(string $class, string $slot): array
	{
		$owners = [];
		foreach (LayoutData::Roles as $candidate => $roles) {
			if (($class === '*' || $class === $candidate) && isset($roles[$slot])) {
				$owners[] = $candidate;
			}
		}

		return $owners;
	}


	/**
	 * Whether the slot of any of the classes holds a list of the kind the part needs: separators are the
	 * business of a separated list alone, items are of either.
	 * @param list<class-string<Node>> $owners
	 */
	private static function holdsList(array $owners, string $slot, string $part): bool
	{
		$lists = $part === ':separator'
			? [SeparatedNodeList::class]
			: [SeparatedNodeList::class, PlainNodeList::class];
		return array_any($owners, fn(string $owner) => ($type = new \ReflectionProperty($owner, $slot)->getType()) instanceof \ReflectionNamedType
			&& in_array($type->getName(), $lists, true));
	}


	/**
	 * @param array<string, list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>> $side
	 * @param Claim|\Closure(Gap): ?Claim|null $claim
	 */
	private function claim(array &$side, string $name, string $key, Rule $rule, Claim|\Closure|null $claim): void
	{
		if ($claim === null) {
			return;
		}

		// two plain claims share a slot only on different components; what a closure decides is seen at the gap
		foreach ($side[$key] ?? [] as $other) {
			if ($claim instanceof Claim && $other[1] instanceof Claim && $claim->overlaps($other[1])) {
				self::refuseSecond($other[0], $rule, $name, $key);
			}
		}

		if ($claim instanceof \Closure && $this->profiler) {
			$claim = self::measureClaim($claim, $rule::class, $this->profiler);
		}

		$side[$key][] = [$rule, $claim, $key];
	}


	/**
	 * @param \Closure(Gap): ?Claim $claim
	 * @return \Closure(Gap): ?Claim
	 */
	private static function measureClaim(\Closure $claim, string $rule, Profiler $profiler): \Closure
	{
		return static function (Gap $gap) use ($claim, $rule, $profiler): ?Claim {
			$start = hrtime(true);
			try {
				return $claim($gap);
			} finally {
				$profiler->addClaim($rule, hrtime(true) - $start);
			}
		};
	}


	/** @throws ConfigurationException */
	public static function refuseSecond(Rule $first, Rule $second, string $side, string $key): never
	{
		throw new ConfigurationException(sprintf(
			'Rules `%s` and `%s` both govern the whitespace %s `%s`.',
			$first::class,
			$second::class,
			$side,
			$key,
		), docs: 'whitespace-rules#who-wins');
	}


	public function isEmpty(): bool
	{
		return $this->before === [] && $this->after === [];
	}


	/**
	 * The slots of the class somebody claims a side of.
	 * @param class-string<Node> $class
	 * @return list<string>
	 */
	public function getClaimedSlots(string $class): array
	{
		if (isset($this->claimedSlots[$class])) {
			return $this->claimedSlots[$class];
		}

		$slots = [];
		foreach (array_keys(LayoutData::Roles[$class] ?? []) as $slot) {
			if (
				isset($this->before["$class.$slot"])
				|| isset($this->before["*.$slot"])
				|| isset($this->after["$class.$slot"])
				|| isset($this->after["*.$slot"])
			) {
				$slots[] = $slot;
			}
		}

		return $this->claimedSlots[$class] = $slots;
	}


	/**
	 * The claims before a slot of a class: those of the class, then those of `'*'`, and for the items or
	 * separators of a list those of every list.
	 * @return list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>
	 */
	public function getBefore(string $class, string $slot): array
	{
		return $this->beforeOf[$class][$slot] ??= self::collect($this->before, $class, $slot);
	}


	/**
	 * The claims after a slot of a class, in the order of getBefore().
	 * @return list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>
	 */
	public function getAfter(string $class, string $slot): array
	{
		return $this->afterOf[$class][$slot] ??= self::collect($this->after, $class, $slot);
	}


	/**
	 * @param array<string, list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>> $side
	 * @return list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>
	 */
	private static function collect(array $side, string $class, string $slot): array
	{
		return [
			...$side["$class.$slot"] ?? [],
			...$side["*.$slot"] ?? [],
			...(str_ends_with($slot, ':separator') ? $side['*.*:separator'] ?? [] : []),
			...(str_ends_with($slot, ':item') ? $side['*.*:item'] ?? [] : []),
		];
	}
}
