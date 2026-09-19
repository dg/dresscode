<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use Nette\Neon\Entity;
use Nette\Neon\Neon;
use Nette\Schema\Context;
use Nette\Schema\Elements\AnyOf;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use function is_bool, is_float, is_int, is_string;


/**
 * The options of the rules fed with a map of members, so that all of them read a key, a value written as code and
 * a withdrawn entry the same way.
 * @internal
 */
final class MemberMaps
{
	/** The value by which a later layer of the configuration withdraws an entry of an earlier one. */
	public const Keep = 'keep';


	/**
	 * A map of members, `Class::name`, `Class::name()`, `Class::$name` or `Class::name($argument, ...)`, to values of
	 * the given schema; a key that does not read as a member is an error of the configuration, and so is a value
	 * the rule cannot make anything of, which is what the closure read() is given says by throwing.
	 * @param  ?\Closure(mixed, MemberPattern): mixed  $convert
	 */
	public static function map(Schema $value, string $description, ?\Closure $convert = null): Schema
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
	 * A value written as code: a string holding PHP, or what NEON reads as an entity, `isFilled()`, `get($name)`,
	 * whose arguments are read the way NEON gives them. `$name`, `...$args` and `...` are placeholders, a number,
	 * true, false and null themselves, `Class::NAME` a constant of a class, a nested entity a call, and any other
	 * string a string, whether quoted or not, so `isMethod(POST)` is `isMethod('POST')`. Whatever that cannot say,
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
			throw new \InvalidArgumentException('A value written as code is one call; a chain of them has to be written as a string holding the whole code.');
		}

		$arguments = [];
		foreach ($entity->attributes as $name => $argument) {
			$arguments[] = (is_string($name) ? "$name: " : '') . match (true) {
				$argument instanceof Entity => self::printEntity($argument),
				is_string($argument) => preg_match('~^(\.\.\.(\$\w+)?|\$\w+|\\\\?\w+(\\\\\w+)*::\w+)$~D', $argument)
					? $argument
					: "'" . addcslashes($argument, "'\\") . "'",
				$argument === null, is_bool($argument), is_int($argument), is_float($argument) => strtolower(var_export($argument, true)),
				default => throw new \InvalidArgumentException("The code $entity->value(...) holds an argument that is no code, " . get_debug_type($argument) . '; it has to be written as a string holding the whole code.'),
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
	public static function read(array $options, \Closure $convert): array
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
}
