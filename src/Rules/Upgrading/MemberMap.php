<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, Types};
use DressCode\{RuleContext, Values};
use PhpSyntax\Builder;
use PhpSyntax\Nodes\Expression\{MethodCallNode, NewNode, StaticMethodCallNode};


/**
 * The entries of a map of members by the lowercased name of the member, which is what a rule looks a node up by.
 * @template T
 */
final readonly class MemberMap
{
	private function __construct(
		/** @var array<string, list<array{MemberPattern, T}>> */
		private array $byName,
	) {
	}


	/**
	 * The map of the decision as the project resolved it, with what the rule keeps of each value; without `$convert` the
	 * value as the grammar reads it.
	 * @template V
	 * @param  ?\Closure(mixed, MemberPattern): V  $convert
	 * @return ($convert is null ? self<mixed> : self<V>)
	 */
	public static function fromValues(Values $values, string $path, ?\Closure $convert = null): self
	{
		return self::fromEntries($values->readMap($path), $convert ?? fn(mixed $value) => $value);
	}


	/**
	 * @template V
	 * @param  array<string, mixed>  $options  the entries `Values::readMap()` gives
	 * @param  \Closure(mixed, MemberPattern): V  $convert  what the rule keeps of a value
	 * @return self<V>
	 */
	public static function fromEntries(array $options, \Closure $convert): self
	{
		$byName = [];
		foreach ($options as $key => $value) {
			$pattern = MemberPattern::fromKey((string) $key);
			$byName[$pattern->getLookupName()][] = [$pattern, $convert($value, $pattern)];
		}

		return new self($byName);
	}


	/**
	 * The entries of the lowercased name, in the order the map writes them, which `MemberMaps::findEntry()` and its kin
	 * look a use up among.
	 * @return list<array{MemberPattern, T}>
	 */
	public function getEntries(string $name): array
	{
		return $this->byName[$name] ?? [];
	}


	/** Whether a key of the map is of the access. */
	public function has(MemberAccess $access, Types $types): bool
	{
		return array_any($this->getEntries(strtolower($access->name)), fn(array $entry) => $entry[0]->matches($access, $types));
	}


	/**
	 * The entry the call is of by the shape of its arguments, the most specific one, with what it binds them to; null
	 * for none. An instantiation is an access of the constructor of the class it creates.
	 * @return ?MapMatch<T>
	 */
	public function findCall(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): ?MapMatch
	{
		// the types are asked only about a name the map knows
		$name = MemberMaps::findLookupName($node);
		$entries = $name === null ? [] : $this->getEntries($name);
		if ($entries === []) {
			return null;
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findConstructorAccess($node) ?? $types->findMemberAccess($node);
		if ($access === null) {
			return null;
		}

		$arguments = $node->arguments ?? (new Builder)->arguments([]);
		return MemberMaps::findEntry($entries, $access, $types, $arguments, $types->findParameters($access));
	}
}
