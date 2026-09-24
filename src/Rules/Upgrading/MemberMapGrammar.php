<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use Nette\Neon\{Entity, Neon};
use Nette\Schema\{Context, Expect, Schema};
use Nette\Schema\Elements\{AnyOf, Type};
use function is_bool, is_float, is_int, is_string;


/**
 * The grammar of the maps of members, so that every rule fed with one reads a key, a value written as code and a
 * withdrawn entry the same way.
 */
final class MemberMapGrammar
{
	/** The value by which a later layer of the configuration withdraws an entry of an earlier one. */
	public const Keep = 'keep';

	/** the paths of the maps of `upgrading.libraries` */
	public const ReplacedClasses = 'upgrading.libraries.replacedClasses';
	public const ReplacedFunctions = 'upgrading.libraries.replacedFunctions';
	public const ReplacedMembers = 'upgrading.libraries.replacedMembers';
	public const ReplacedCalls = 'upgrading.libraries.replacedCalls';
	public const ForbiddenClasses = 'upgrading.libraries.forbiddenClasses';
	public const ForbiddenFunctions = 'upgrading.libraries.forbiddenFunctions';
	public const ForbiddenMembers = 'upgrading.libraries.forbiddenMembers';
	public const AttributeForAnnotation = 'upgrading.libraries.attributeForAnnotation';
	public const AttributeForMember = 'upgrading.libraries.attributeForMember';


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
}
