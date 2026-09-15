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
	 * `$convert` throws for, the same closure `indexEntries()` is given.
	 * @param  ?\Closure(mixed, MemberPattern): mixed  $convert
	 */
	public static function map(Schema $value, string $description, ?\Closure $convert = null): Type
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
	public static function code(): AnyOf
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
	 * The entries of a validated map by the lowercased name of the member, which is what a rule looks a node up by,
	 * without the withdrawn ones.
	 * @template T
	 * @param  array<string, mixed>  $options
	 * @param  \Closure(mixed, MemberPattern): T  $convert  what the rule keeps of a value
	 * @return array<string, list<array{MemberPattern, T}>>
	 */
	public static function indexEntries(array $options, \Closure $convert): array
	{
		$entries = [];
		foreach ($options as $key => $value) {
			if ($value !== self::Keep) {
				$pattern = MemberPattern::fromKey($key);
				$entries[$pattern->getLookupName()][] = [$pattern, $convert($value, $pattern)];
			}
		}

		return $entries;
	}


	/**
	 * The entry the access is found under, the first in the order `order()` gives whose key the access is of and,
	 * where the arguments of the call are given, whose arguments they bind to, with the types, the key whose arguments
	 * have the more specific shape asked first; null where none is.
	 * @template T
	 * @param  list<array{MemberPattern, T}>  $entries  of the name of the member, as `indexEntries()` gives them
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 * @return ?MapEntry<T>
	 */
	public static function findEntry(
		array $entries,
		MemberAccess $access,
		Types $types,
		?ArgumentListNode $arguments = null,
		?array $parameters = null,
	): ?MapEntry
	{
		foreach (self::order($entries, $types, specificFirst: $arguments !== null) as [$pattern, $value]) {
			$bindings = $arguments === null ? null : $pattern->bind($access, $arguments, $parameters, $types);
			if ($arguments === null ? $pattern->matches($access, $types) : $bindings !== null) {
				return new MapEntry($pattern, $value, $bindings);
			}
		}

		return null;
	}


	/**
	 * The lowercased name the entries `indexEntries()` gives are looked up by for a node reaching a member, `__construct` for
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
	 * The entries of one name in the order they are asked in: with `$specificFirst` the key whose arguments have the more
	 * specific shape first, then an entry of a class before one of its ancestor, so that of two keys an access fits
	 * both, the one of the class nearer to it decides, then as the map writes them.
	 * @template T of array{MemberPattern, mixed}
	 * @param  list<T>  $entries
	 * @return list<T>
	 */
	public static function order(array $entries, Types $types, bool $specificFirst = false): array
	{
		$keys = array_keys($entries);
		usort($keys, function (int $a, int $b) use ($entries, $types, $specificFirst): int {
			[$first, $second] = [$entries[$a][0]->class, $entries[$b][0]->class];
			$result = $specificFirst ? $entries[$a][0]->compareSpecificity($entries[$b][0]) : 0;
			return match (true) {
				$result !== 0 => $result,
				strcasecmp($first, $second) === 0 => $a <=> $b,
				$types->isSubtype($first, $second) === Tristate::Yes => -1,
				$types->isSubtype($second, $first) === Tristate::Yes => 1,
				default => $a <=> $b,
			};
		});
		return array_map(fn(int $key) => $entries[$key], $keys);
	}
}
