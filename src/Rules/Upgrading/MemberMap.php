<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, Parameter, Types};
use DressCode\{RuleContext, Tristate, Values};
use PhpSyntax\Builder;
use PhpSyntax\Nodes\{ArgumentListNode, IdentifierNode};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\MethodNode;


/**
 * The entries of a map of members by the lowercased name of the member, which is what a rule looks a node up by, and
 * the entry a use or a declaration of a member is of.
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
	 * The entries of the lowercased name, in the order the map writes them; a rule asks them before it asks the types,
	 * which it then asks only about a name the map knows.
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
	 * The entry the access is of, the first `findDecidingEntry()` gives of those whose key the access is of and, where
	 * the arguments of the call are given, whose arguments they bind to, the key whose arguments have the more specific
	 * shape asked first; null where none is.
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 * @return ?MapMatch<T>
	 */
	public function findAccess(
		MemberAccess $access,
		Types $types,
		?ArgumentListNode $arguments = null,
		?array $parameters = null,
	): ?MapMatch
	{
		$bindings = [];
		$entry = $this->findDecidingEntry(
			strtolower($access->name),
			$types,
			function (array $entry) use ($access, $types, $arguments, $parameters, &$bindings): bool {
				if ($arguments === null) {
					return $entry[0]->matches($access, $types);
				}

				$bound = $entry[0]->bind($access, $arguments, $parameters, $types);
				if ($bound !== null) {
					$bindings[spl_object_id($entry[0])] = $bound;
				}

				return $bound !== null;
			},
			specificFirst: $arguments !== null,
		);
		return $entry === null
			? null
			: new MapMatch($entry[0], $entry[1], $access, $bindings[spl_object_id($entry[0])] ?? null);
	}


	/**
	 * The entry the call is of by the shape of its arguments, the most specific one, with what it binds them to; null
	 * for none. An instantiation is an access of the constructor of the class it creates.
	 * @return ?MapMatch<T>
	 */
	public function findCall(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): ?MapMatch
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
	 * @return ?array{MemberPattern, T}
	 */
	public function findDeclaration(MethodNode $declaration, Types $types, bool $anyArguments = false, bool $specificFirst = false): ?array
	{
		$name = strtolower($declaration->name->text);
		$class = $this->getEntries($name) === [] ? null : $types->findDeclaringClass($declaration);
		return $class === null
			? null
			: $this->findDecidingEntry(
				$name,
				$types,
				fn(array $entry) => (!$anyArguments || $entry[0]->takesAnyArguments())
					&& $entry[0]->matchesMethodDeclaration($class, $declaration->name->text, $types),
				specificFirst: $specificFirst,
			);
	}


	/**
	 * The entry of the lowercased name that decides among those `$accepts` takes, the first as the map writes them that
	 * no other one taken precedes: with `$specificFirst` the key whose arguments have the more specific shape precedes,
	 * then the key of a class precedes that of its ancestor, so that of two keys an access fits both, the one of the
	 * class nearer to it decides; null where none is taken.
	 * @param  \Closure(array{MemberPattern, T}): bool  $accepts
	 * @return ?array{MemberPattern, T}
	 */
	public function findDecidingEntry(string $name, Types $types, \Closure $accepts, bool $specificFirst = false): ?array
	{
		// the order is partial, which a sort cannot be given
		$accepted = array_filter($this->getEntries($name), $accepts);
		return array_find(
			$accepted,
			fn(array $entry) => !array_any($accepted, fn(array $other) => self::precedes($other[0], $entry[0], $types, $specificFirst)),
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
