<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use PHPStan\PhpDocParser\Ast\Type;
use function in_array;


/**
 * What the rules ask of a doc comment type beside a native one: whether the two say the same, and whether a type is
 * iterable.
 * @internal
 */
final class NativeType
{
	public const Return = 'return';

	public const Builtin = [
		'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed', 'null', 'void', 'never',
		'false', 'true', 'self', 'static', 'parent',
	];

	private const Iterable = ['array', 'iterable'];


	/**
	 * Whether the annotation says exactly what the native type says: an identifier, a nullable one or a union
	 * of identifiers naming the same types; `int[]` or `array<string, Foo>` say more than `array`.
	 */
	public static function matches(Type\TypeNode $annotation, string $native): bool
	{
		return self::isPlain($annotation) && self::normalize((string) $annotation) === self::normalize($native);
	}


	private static function isPlain(Type\TypeNode $type): bool
	{
		if ($type instanceof Type\UnionTypeNode) {
			return array_all($type->types, fn(Type\TypeNode $member) => self::isPlain($member));
		}

		return match (true) {
			$type instanceof Type\IdentifierTypeNode => !in_array(strtolower($type->name), ['static', '$this'], true),
			$type instanceof Type\NullableTypeNode => self::isPlain($type->type),
			default => false,
		};
	}


	/** Canonical form of a type: no parentheses, spaces or leading backslashes, ?T as T|null, members sorted, builtins lowercased. */
	private static function normalize(string $type): string
	{
		$type = str_replace(['(', ')', ' ', '\\'], '', $type);
		if (str_starts_with($type, '?')) {
			$type = substr($type, 1) . '|null';
		}

		$members = array_map(
			fn(string $member) => in_array(strtolower($member), self::Builtin, true) ? strtolower($member) : $member,
			explode('|', $type),
		);
		sort($members);
		return implode('|', $members);
	}


	/**
	 * Whether the native type is iterable, or a class configured as traversable.
	 * @param  list<string>  $traversableClasses  lowercased fully qualified names
	 * @param  callable(string): string  $resolveClass
	 */
	public static function isTraversable(string $name, array $traversableClasses, callable $resolveClass): bool
	{
		return in_array(strtolower($name), self::Iterable, true)
			|| (
				preg_match('~^\\\?[A-Za-z_\x80-\xff][\w\x80-\xff\\\]*$~', $name) === 1
				&& !in_array(strtolower($name), self::Builtin, true)
				&& in_array(strtolower(ltrim($resolveClass($name), '\\')), $traversableClasses, true)
			);
	}


	/** Whether the annotation is a bare `array` or `iterable`, saying nothing about the items. */
	public static function isPlainIterable(Type\TypeNode $type): bool
	{
		return $type instanceof Type\IdentifierTypeNode && in_array(strtolower($type->name), self::Iterable, true);
	}
}
