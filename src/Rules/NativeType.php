<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use PHPStan\PhpDocParser\Ast\{ConstExpr, Type};
use PhpSyntax\Node;
use PhpSyntax\Nodes\{Expression, NameNode, Scalar};
use function count, in_array;


/**
 * The native type a doc comment type stands for, as far as the PHP version can express it: `int[]` is
 * `array`, `class-string` is `string`, `Foo|null` is `?Foo`, `scalar` is `string|int|float|bool`.
 * @internal
 */
final class NativeType
{
	public const Parameter = 'parameter';
	public const Return = 'return';
	public const Property = 'property';

	public const Builtin = [
		'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed', 'null', 'void', 'never',
		'false', 'true', 'self', 'static', 'parent',
	];

	/** the other names PHP gives a scalar type, which a doc comment may write */
	public const Synonyms = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'];

	private const Iterable = ['array', 'iterable'];

	private const Aliases = self::Synonyms + [
		'positive-int' => 'int', 'non-positive-int' => 'int', 'negative-int' => 'int', 'non-negative-int' => 'int',
		'literal-int' => 'int', 'int-mask' => 'int', 'callable-array' => 'callable', 'callable-string' => 'callable',
		'non-empty-array' => 'array', 'list' => 'array', 'non-empty-list' => 'array',
	];

	private const Unofficial = [
		'scalar' => ['string', 'int', 'float', 'bool'],
		'numeric' => ['int', 'float', 'string'],
		'array-key' => ['int', 'string'],
	];


	/**
	 * The native type for the annotation in the given place, null when PHP cannot express it or when it would
	 * say nothing (a template type, a type alias, a pseudo-type). Traversable types with an item specification (`Foo[]`,
	 * `array<int, Foo>`) become the bare traversable type.
	 * @param  list<string>  $traversableClasses  lowercased fully qualified names of classes treated like iterables
	 * @param  list<string>  $localTypes  names of templates and type aliases in scope
	 * @param  callable(string): string  $resolveClass  fully qualified name of a class as written in the annotation
	 */
	public static function fromAnnotation(
		Type\TypeNode $type,
		string $place,
		string $phpVersion,
		array $traversableClasses,
		array $localTypes,
		callable $resolveClass,
	): ?string
	{
		$nullable = false;
		if ($type instanceof Type\NullableTypeNode) {
			$nullable = true;
			$type = $type->type;
		}

		$names = [];
		if ($type instanceof Type\UnionTypeNode || $type instanceof Type\IntersectionTypeNode) {
			$traversable = $itemsSpecified = false;
			foreach ($type->types as $member) {
				$name = self::findNativeName($member);
				if ($name === null) {
					return null;
				} elseif (strtolower($name) === 'null') {
					$nullable = true;
					continue;
				}

				$isArrayShape = $member instanceof Type\ArrayTypeNode || $member instanceof Type\ArrayShapeNode;
				$itemsSpecified = $itemsSpecified || $isArrayShape;
				$traversable = $traversable || (!$isArrayShape && self::isTraversable($name, $traversableClasses, $resolveClass));
				$names[] = $name;
			}

			if ($itemsSpecified && $traversable) { // Foo[]|Traversable: the array part only says what the items are
				$names = array_values(array_filter(
					$names,
					fn(string $name) => strtolower($name) !== 'array' && self::isTraversable($name, $traversableClasses, $resolveClass),
				));
			}

		} else {
			$name = self::findNativeName($type);
			if ($name === null) {
				return null;
			}

			$names[] = $name;
		}

		$expanded = [];
		foreach ($names as $name) {
			$expanded = [...$expanded, ...(self::Unofficial[strtolower($name)] ?? [$name])];
		}

		if (array_intersect($expanded, $localTypes) !== []) {
			return null;
		}

		$names = self::reduce($expanded, $phpVersion, $resolveClass);
		$intersection = $type instanceof Type\IntersectionTypeNode;
		if (
			$names === []
			|| ($intersection && ($nullable || (count($names) > 1 && version_compare($phpVersion, '8.1', '<'))))
		) {
			return null;
		}

		foreach ($names as $name) {
			$lower = strtolower($name);
			if (
				!self::isValid($name, $place, $phpVersion, count($names) > 1)
				|| (in_array($lower, ['void', 'never'], true) && (count($names) > 1 || $nullable))
				|| ($lower === 'null' && $nullable)
				|| ($intersection && in_array($lower, self::Builtin, true) && $lower !== 'self' && $lower !== 'parent')
			) {
				return null;
			}
		}

		if (in_array('mixed', array_map('strtolower', $names), true)) {
			return 'mixed';
		}

		return match (true) {
			$intersection => implode('&', $names),
			$nullable && count($names) === 1 => '?' . $names[0],
			$nullable => implode('|', $names) . '|null',
			default => implode('|', $names),
		};
	}


	/** The native type of a value written out, null for a value whose type the code does not show. */
	public static function fromValue(Node $value): ?string
	{
		return match (true) {
			$value instanceof Scalar\StringNode, $value instanceof Scalar\HeredocNode => 'string',
			$value instanceof Scalar\IntegerNode => 'int',
			$value instanceof Scalar\FloatNode => 'float',
			$value instanceof Scalar\BooleanNode => 'bool',
			$value instanceof Expression\ArrayNode => 'array',
			$value instanceof Expression\UnaryOpNode && $value->operator->is(['-', '+']) => self::fromValue($value->expression),
			$value instanceof Expression\BinaryOpNode && $value->operator->is('.') => 'string',
			default => null,
		};
	}


	/**
	 * Whether the annotation says exactly what the native type says: an identifier, a nullable one or a union
	 * of identifiers naming the same types; `int[]` or `array<string, Foo>` say more than `array`.
	 */
	public static function matches(Type\TypeNode $annotation, string $native): bool
	{
		return self::isPlain($annotation) && self::normalize((string) $annotation) === self::normalize($native);
	}


	/**
	 * Whether the native type is the one PHPStan describes, its classes fully qualified without a leading backslash
	 * and a nullable type as a union with null; an intersection is never the same.
	 * @param  callable(string): string  $resolveClass  fully qualified name of a class as written in the native type
	 */
	public static function isDescribedAs(string $native, string $described, callable $resolveClass): bool
	{
		if (str_contains($native, '&') || str_contains($described, '&')) {
			return false;
		}

		$canonize = function (string $type, ?callable $resolveClass): array {
			$members = explode('|', ltrim($type, '?'));
			if (str_starts_with($type, '?')) {
				$members[] = 'null';
			}

			$members = array_map(
				fn(string $member) => strtolower(in_array(strtolower($member), self::Builtin, true) || $resolveClass === null ? $member : ltrim($resolveClass($member), '\\')),
				$members,
			);
			sort($members);
			return $members;
		};
		return $canonize($native, $resolveClass) === $canonize($described, null);
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


	/** The native name behind one type node, null for a node that is not one type. */
	private static function findNativeName(Type\TypeNode $type): ?string
	{
		return match (true) {
			$type instanceof Type\IdentifierTypeNode => self::findNativeIdentifier($type->name),
			$type instanceof Type\GenericTypeNode => self::findNativeIdentifier($type->type->name),
			$type instanceof Type\ThisTypeNode => 'static',
			$type instanceof Type\CallableTypeNode => self::findNativeIdentifier($type->identifier->name),
			$type instanceof Type\ArrayTypeNode, $type instanceof Type\ArrayShapeNode => 'array',
			$type instanceof Type\ObjectShapeNode => 'object',
			$type instanceof Type\ConstTypeNode => match (true) {
				$type->constExpr instanceof ConstExpr\ConstExprIntegerNode => 'int',
				$type->constExpr instanceof ConstExpr\ConstExprFloatNode => 'float',
				$type->constExpr instanceof ConstExpr\ConstExprStringNode => 'string',
				default => null,
			},
			default => null,
		};
	}


	/**
	 * The native name behind the identifier, null for one no class can be named by, such as a keyword or a builtin
	 * written with a leading backslash, which PHP refuses.
	 */
	private static function findNativeIdentifier(string $name): ?string
	{
		$lower = strtolower($name);
		$bare = ltrim($lower, '\\');
		return match (true) {
			$bare !== $lower && (in_array($bare, [...self::Builtin, 'resource'], true) || isset(self::Aliases[$bare]) || isset(self::Unofficial[$bare])) => null,
			isset(self::Aliases[$lower]) => self::Aliases[$lower],
			str_ends_with($lower, '-string') => 'string',
			in_array($lower, self::Builtin, true), isset(self::Unofficial[$lower]) => $lower,
			$lower !== 'resource' && NameNode::tryFromText($name)?->isKeyword() === false => $name,
			default => null,
		};
	}


	/**
	 * The members without one written twice or covered by another, which PHP refuses as redundant: `true|false` and
	 * `bool|false` are `bool`, `iterable|array` and `iterable|Traversable` are `iterable`, `object|Foo` is `object`;
	 * `true` is `bool` before PHP 8.2.
	 * @param  list<string>  $names
	 * @param  callable(string): string  $resolveClass
	 * @return list<string>
	 */
	private static function reduce(array $names, string $phpVersion, callable $resolveClass): array
	{
		$members = [];
		foreach ($names as $name) {
			$lower = strtolower($name);
			$name = $lower === 'true' && version_compare($phpVersion, '8.2', '<') ? 'bool' : $name;
			$key = in_array($lower, self::Builtin, true) ? strtolower($name) : strtolower(ltrim($resolveClass($name), '\\'));
			$members[$key] ??= $name;
		}

		if (isset($members['true'], $members['false'])) {
			$members['bool'] ??= 'bool';
		}

		$covered = [
			...(isset($members['bool']) ? ['true', 'false'] : []),
			...(isset($members['iterable']) ? ['array', 'traversable'] : []),
			...(isset($members['object']) ? ['self', 'static', 'parent'] : []),
		];
		return array_values(array_filter(
			$members,
			fn(string $key) => !in_array($key, $covered, true)
				&& !(isset($members['object']) && !in_array($key, self::Builtin, true)),
			ARRAY_FILTER_USE_KEY,
		));
	}


	private static function isValid(string $name, string $place, string $phpVersion, bool $inUnion): bool
	{
		$lower = strtolower($name);
		return match ($lower) {
			'object', 'mixed', 'iterable', 'self', 'parent' => true,
			'static', 'void' => $place === self::Return,
			'never' => $place === self::Return && version_compare($phpVersion, '8.1', '>='),
			'null', 'true' => version_compare($phpVersion, '8.2', '>='),
			'false' => $inUnion || version_compare($phpVersion, '8.2', '>='),
			'callable' => $place !== self::Property,
			default => true,
		};
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
