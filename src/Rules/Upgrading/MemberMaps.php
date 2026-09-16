<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, Parameter, Types};
use DressCode\Tristate;
use Nette\Neon\{Entity, Neon};
use Nette\Schema\{Context, Expect, Schema};
use Nette\Schema\Elements\{AnyOf, Type};
use PhpSyntax\Nodes\{ArgumentListNode, IdentifierNode};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function is_bool, is_float, is_int, is_string;


/**
 * The options of the rules fed with a map of members, so that all of them read a key, a value written as code and
 * a withdrawn entry the same way, and look a use up among the entries and name its member the same way.
 */
final class MemberMaps
{
	/** The value by which a later layer of the configuration withdraws an entry of an earlier one. */
	public const Keep = 'keep';


	/**
	 * A map of members, `Class::name`, `Class::name()`, `Class::$name` or `Class::name($argument, ...)`, to values of
	 * the given schema; a key that does not read as a member is an error of the configuration, and so is a value
	 * `$convert` throws for, the same closure `MemberMap::fromValues()` is given.
	 * @param  ?\Closure(mixed, MemberPattern): mixed  $convert
	 */
	public static function createMapSchema(Schema $value, string $description, ?\Closure $convert = null): Type
	{
		return Expect::arrayOf(Expect::anyOf(self::Keep, $value), Expect::string())
			->description($description)
			->transform(function (array $map, Context $context) use ($convert): array {
				foreach ($map as $key => $item) {
					try {
						$pattern = MemberPattern::fromKey((string) $key);
						if ($convert !== null && $item !== self::Keep) {
							$convert($item, $pattern);
						}
					} catch (\InvalidArgumentException $e) {
						$context->addError($e->getMessage(), 'dresscode.memberMap');
					}
				}

				return $map;
			});
	}


	/**
	 * A value written as code: a string holding PHP, or what NEON reads as an entity, `isPaid()`, `get($name)`,
	 * whose arguments are read the way NEON gives them. `$name`, `...$args` and `...` are placeholders, a number,
	 * true, false and null themselves, `Class::NAME` a constant of a class, a nested entity a call, and any other
	 * string a string, whether quoted or not, so `hasMode(debug)` is `hasMode('debug')`. Whatever that cannot say,
	 * a global constant or an operator, is written as a string holding the whole code.
	 */
	public static function createCodeSchema(): AnyOf
	{
		return Expect::anyOf(Expect::string(), Expect::type(Entity::class))
			->transform(function (string|Entity $value, Context $context): string {
				try {
					return is_string($value) ? $value : self::printEntity($value);
				} catch (\InvalidArgumentException $e) {
					$context->addError($e->getMessage(), 'dresscode.codeEntity');
					return '';
				}
			});
	}


	/** @throws \InvalidArgumentException  for an entity holding what is no code */
	private static function printEntity(Entity $entity): string
	{
		if (!is_string($entity->value) || $entity->value === Neon::Chain) {
			throw new \InvalidArgumentException('A value written as code is one call; a chain of them must be written as a string holding the whole code.');
		}

		$arguments = [];
		foreach ($entity->attributes as $name => $argument) {
			$arguments[] = (is_string($name) ? "$name: " : '') . match (true) {
				$argument instanceof Entity => self::printEntity($argument),
				is_string($argument) => preg_match('~^(\.\.\.(\$\w+)?|\$\w+|\\\\?\w+(\\\\\w+)*::\w+)$~D', $argument)
					? $argument
					: "'" . addcslashes($argument, "'\\") . "'",
				$argument === null, is_bool($argument), is_int($argument), is_float($argument) => strtolower(var_export($argument, true)),
				default => throw new \InvalidArgumentException("The code `$entity->value(...)` holds an argument that is no code, `" . get_debug_type($argument) . '`; it must be written as a string holding the whole code.'),
			};
		}

		return $entity->value . '(' . implode(', ', $arguments) . ')';
	}


	/**
	 * The entry the access is found under, the first `findDecidingEntry()` gives of those whose key the access is of and,
	 * where the arguments of the call are given, whose arguments they bind to, with the types, the key whose arguments
	 * have the more specific shape asked first; null where none is.
	 * @template T
	 * @param  list<array{MemberPattern, T}>  $entries  of the name of the member, as `MemberMap::getEntries()` gives them
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 * @return ?MapMatch<T>
	 */
	public static function findEntry(
		array $entries,
		MemberAccess $access,
		Types $types,
		?ArgumentListNode $arguments = null,
		?array $parameters = null,
	): ?MapMatch
	{
		$bindings = [];
		$entry = self::findDecidingEntry(
			$entries,
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
	 * The entry whose key is the method the declaration overrides, the key whose arguments have the more specific shape
	 * first where `$specificFirst` asks for it; with `$anyArguments` only a key taking any arguments, the declaration
	 * being of no call; null where none is.
	 * @template T
	 * @param  list<array{MemberPattern, T}>  $entries  of the name of the method, as `MemberMap::getEntries()` gives them
	 * @return ?array{MemberPattern, T}
	 */
	public static function findDeclarationEntry(
		array $entries,
		MethodNode $declaration,
		Types $types,
		bool $anyArguments = false,
		bool $specificFirst = false,
	): ?array
	{
		$class = $entries === [] ? null : $types->findDeclaringClass($declaration);
		return $class === null
			? null
			: self::findDecidingEntry(
				$entries,
				$types,
				fn(array $entry) => (!$anyArguments || $entry[0]->takesAnyArguments())
					&& $entry[0]->matchesMethodDeclaration($class, $declaration->name->text, $types),
				specificFirst: $specificFirst,
			);
	}


	/**
	 * The lowercased name `MemberMap::getEntries()` is asked by for a node reaching a member, `__construct` for
	 * an instantiation; null where the name is an expression.
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


	/**
	 * The entry of one name that decides among those `$accepts` takes, the first as the map writes them that no other
	 * one taken precedes: with `$specificFirst` the key whose arguments have the more specific shape precedes, then
	 * the key of a class precedes that of its ancestor, so that of two keys an access fits both, the one of the class
	 * nearer to it decides; null where none is taken.
	 * @template T of array{MemberPattern, mixed}
	 * @param  list<T>  $entries
	 * @param  \Closure(T): bool  $accepts
	 * @return ?T
	 */
	public static function findDecidingEntry(array $entries, Types $types, \Closure $accepts, bool $specificFirst = false): ?array
	{
		// the order is partial, which a sort cannot be given
		$accepted = array_filter($entries, $accepts);
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
}
