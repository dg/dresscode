<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;

use DressCode\Analyses\{MemberAccess, Parameter, Types};
use DressCode\Config\Layer;
use DressCode\{RuleContext, Tristate, Values};
use PhpSyntax\Builder;
use PhpSyntax\Nodes\{ArgumentListNode, IdentifierNode};
use PhpSyntax\Nodes\Expression\{
	ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode,
};
use PhpSyntax\Nodes\Member\MethodNode;


/**
 * The entries of a map of members by the lowercased name of the member, which is what a rule looks a node up by, and
 * the entry a use or a declaration of a member is of.
 * @template T
 */
final readonly class MemberMap
{
	private function __construct(
		/** @var array<string, list<MemberMapEntry<T>>> */
		private array $byName,
	) {
	}


	/**
	 * The map of the decision as the project resolved it, with what the rule keeps of each value; without `$convert` the
	 * value as the grammar reads it.
	 * @template V
	 * @param  ?\Closure(mixed, MemberPattern, string): V  $convert  given the value, the pattern and the key as written
	 * @return ($convert is null ? self<mixed> : self<V>)
	 */
	public static function fromValues(Values $values, string $path, ?\Closure $convert = null): self
	{
		return self::build(
			$values->readMap($path),
			$convert,
			fn(string $key) => $values->getEntryDecision($path, $key),
			fn(string $key) => $values->getEntryOrigin($path, $key),
		);
	}


	/**
	 * The map of entries a use of each of which is reported under the decision.
	 * @template V
	 * @param  array<string, mixed>  $entries  as `Values::readMap()` gives them
	 * @param  \Closure(mixed, MemberPattern, string): V  $convert  what the rule keeps of a value, given also the key as written
	 * @return self<V>
	 */
	public static function fromEntries(array $entries, \Closure $convert, string $decision): self
	{
		return self::build($entries, $convert, fn() => $decision);
	}


	/**
	 * @template V
	 * @param  array<mixed>  $entries
	 * @param  ?\Closure(mixed, MemberPattern, string): V  $convert
	 * @param  \Closure(string): string  $decide  the key as written => the decision its entry is reported under
	 * @param  ?\Closure(string): ?Layer  $findOrigin  the key as written => the section of the upgrading data that laid it
	 * @return self<V>
	 */
	private static function build(array $entries, ?\Closure $convert, \Closure $decide, ?\Closure $findOrigin = null): self
	{
		$byName = [];
		foreach ($entries as $key => $value) {
			if (MemberMapGrammar::isFunctionKey((string) $key)) {
				continue; // a function, which replacedCalls has keys of too, is no member
			}

			$pattern = MemberPattern::fromKey((string) $key);
			$byName[$pattern->getLookupName()][] = new MemberMapEntry(
				$pattern,
				$convert === null ? $value : $convert($value, $pattern, (string) $key),
				$decide((string) $key),
				$findOrigin === null ? null : $findOrigin((string) $key),
			);
		}

		return new self($byName);
	}


	/**
	 * The entries of the name in any letter case, in the order the map writes them; a rule asks them before it asks
	 * the types, which it then asks only about a name the map knows.
	 * @return list<MemberMapEntry<T>>
	 */
	public function getEntries(string $name): array
	{
		return $this->byName[strtolower($name)] ?? [];
	}


	/** Whether a key of the map is of the access. */
	public function has(MemberAccess $access, Types $types): bool
	{
		return array_any($this->getEntries($access->name), fn(MemberMapEntry $entry) => $entry->pattern->matches($access, $types));
	}


	/**
	 * The entry the access is of, the first `findDecidingEntry()` gives of those whose key the access is of and, where
	 * the arguments of the call are given, whose arguments they bind to, the key whose arguments have the more specific
	 * shape asked first; null where none is.
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 * @return ?MemberMapMatch<T>
	 */
	public function findAccess(
		MemberAccess $access,
		Types $types,
		?ArgumentListNode $arguments = null,
		?array $parameters = null,
	): ?MemberMapMatch
	{
		$bindings = [];
		$entry = $this->findDecidingEntry(
			strtolower($access->name),
			$types,
			function (MemberMapEntry $entry) use ($access, $types, $arguments, $parameters, &$bindings): bool {
				if ($arguments === null) {
					return $entry->pattern->matches($access, $types);
				}

				$bound = $entry->pattern->bind($access, $arguments, $parameters, $types);
				if ($bound !== null) {
					$bindings[spl_object_id($entry)] = $bound;
				}

				return $bound !== null;
			},
			specificFirst: $arguments !== null,
		);
		return $entry === null ? null : new MemberMapMatch($entry, $access, $bindings[spl_object_id($entry)] ?? null);
	}


	/**
	 * The entry the call is of by the shape of its arguments, the most specific one, with what it binds them to; null
	 * for none. An instantiation is an access of the constructor of the class it creates.
	 * @return ?MemberMapMatch<T>
	 */
	public function findCall(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): ?MemberMapMatch
	{
		$name = self::findLookupName($node);
		if ($name === null || $this->getEntries($name) === []) {
			return null; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findConstructorAccess($node) ?? $types->findMemberAccess($node);
		return $access === null
			? null
			: $this->findAccess($access, $types, $node->arguments ?? (new Builder)->arguments([]), $types->findParameters($access));
	}


	/**
	 * The entry whose key is the method the declaration overrides, the key whose arguments have the more specific shape
	 * first where `$specificFirst` asks for it; with `$anyArguments` only a key taking any arguments, the declaration
	 * being of no call; null where none is.
	 * @return ?MemberMapEntry<T>
	 */
	public function findDeclaration(
		MethodNode $declaration,
		Types $types,
		bool $anyArguments = false,
		bool $specificFirst = false,
	): ?MemberMapEntry
	{
		$name = strtolower($declaration->name->text);
		$class = $this->getEntries($name) === [] ? null : $types->findDeclaringClass($declaration);
		return $class === null
			? null
			: $this->findDecidingEntry(
				$name,
				$types,
				fn(MemberMapEntry $entry) => (!$anyArguments || $entry->pattern->takesAnyArguments())
					&& $entry->pattern->matchesMethodDeclaration($class, $declaration->name->text, $types),
				specificFirst: $specificFirst,
			);
	}


	/**
	 * The entry of the lowercased name that decides among those `$accepts` takes, the first as the map writes them that
	 * no other one taken precedes: with `$specificFirst` the key whose arguments have the more specific shape precedes,
	 * then the key of a class precedes that of its ancestor, so that of two keys an access fits both, the one of the
	 * class nearer to it decides; null where none is taken.
	 * @param  \Closure(MemberMapEntry<T>): bool  $accepts
	 * @return ?MemberMapEntry<T>
	 */
	public function findDecidingEntry(string $name, Types $types, \Closure $accepts, bool $specificFirst = false): ?MemberMapEntry
	{
		// the order is partial, which a sort cannot be given
		$accepted = array_filter($this->getEntries($name), $accepts);
		return array_find(
			$accepted,
			fn(MemberMapEntry $entry) => !array_any(
				$accepted,
				fn(MemberMapEntry $other) => self::precedes($other->pattern, $entry->pattern, $types, $specificFirst),
			),
		);
	}


	private static function precedes(MemberPattern $a, MemberPattern $b, Types $types, bool $specificFirst): bool
	{
		$result = $specificFirst ? $a->compareSpecificity($b) : 0;
		return $result < 0
			|| ($result === 0 && strcasecmp($a->class, $b->class) !== 0 && $types->isSubtype($a->class, $b->class) === Tristate::Yes);
	}


	/**
	 * The lowercased name `getEntries()` is asked by for a node reaching a member, `__construct` for an instantiation;
	 * null where the name is an expression.
	 */
	public static function findLookupName(
		ClassConstantFetchNode|MethodCallNode|StaticMethodCallNode|PropertyFetchNode|StaticPropertyFetchNode|NewNode $node,
	): ?string
	{
		$name = match (true) {
			$node instanceof NewNode => '__construct',
			$node instanceof StaticPropertyFetchNode => $node->plainName,
			$node->name instanceof IdentifierNode => $node->name->text,
			default => null,
		};
		return $name === null ? null : strtolower($name);
	}
}
