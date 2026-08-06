<?php declare(strict_types=1);

namespace DressCode;

use PHPStan\PhpDocParser\Ast\ConstExpr;
use PHPStan\PhpDocParser\Ast\Type;
use PhpSyntax\PhpVersion;
use function count;


/**
 * The native type a doc comment type stands for, as far as the PHP version can express it: `int[]` is
 * `array`, `class-string` is `string`, `Foo|null` is `?Foo`, `scalar` is `string|int|float|bool`.
 */
final class NativeType
{
	public const Parameter = 'parameter';
	public const Return = 'return';
	public const Property = 'property';

	private const Simple = ['int', 'float', 'string', 'bool', 'callable', 'self', 'array', 'iterable', 'false'];
	private const Iterable = ['array', 'iterable'];

	private const Builtin = [
		'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed', 'null', 'void', 'never',
		'false', 'true', 'self', 'static', 'parent',
	];

	private const Aliases = [
		'integer' => 'int', 'boolean' => 'bool', 'double' => 'float',
		'positive-int' => 'int', 'non-positive-int' => 'int', 'negative-int' => 'int', 'non-negative-int' => 'int',
		'literal-int' => 'int', 'int-mask' => 'int', 'callable-array' => 'callable', 'callable-string' => 'callable',
		'non-empty-array' => 'array', 'list' => 'array', 'non-empty-list' => 'array',
	];

	private const Unofficial = [
		'scalar' => ['string', 'int', 'float', 'bool'],
		'numeric' => ['int', 'float', 'string'],
		'array-key' => ['int', 'string'],
	];

	private const PseudoTypes = [
		'resource', '$this', 'closed-resource', 'open-resource', 'key-of', 'value-of', 'array-key', 'scalar', 'numeric',
	];


	/**
	 * The native type for the annotation in the given place, null when PHP cannot express it or when it would
	 * say nothing (a template type, a pseudo-type). Traversable types with an item specification (`Foo[]`,
	 * `array<int, Foo>`) become the bare traversable type.
	 * @param  self::Parameter|self::Return|self::Property  $place
	 * @param  list<string>  $traversableTypeHints  lowercased fully qualified names of classes treated like iterables
	 * @param  list<string>  $templates  names of template types in scope
	 * @param  callable(string): string  $resolveClass  fully qualified name of a class as written in the annotation
	 */
	public static function fromAnnotation(
		Type\TypeNode $type,
		string $place,
		PhpVersion $php,
		array $traversableTypeHints,
		array $templates,
		callable $resolveClass,
	): ?string
	{
		$nullable = false;
		if ($type instanceof Type\NullableTypeNode) {
			$nullable = true;
			$type = $type->type;
		}

		$hints = [];
		if ($type instanceof Type\UnionTypeNode || $type instanceof Type\IntersectionTypeNode) {
			$traversable = $itemsSpecified = false;
			foreach ($type->types as $member) {
				$hint = self::oneType($member);
				if ($hint === null) {
					return null;
				} elseif (strtolower($hint) === 'null') {
					$nullable = true;
					continue;
				}

				$isArrayShape = $member instanceof Type\ArrayTypeNode || $member instanceof Type\ArrayShapeNode;
				$itemsSpecified = $itemsSpecified || $isArrayShape;
				$traversable = $traversable || (!$isArrayShape && self::isTraversable($hint, $traversableTypeHints, $resolveClass));
				$hints[] = $hint;
			}

			if ($itemsSpecified && $traversable) { // Foo[]|Traversable: the array part only says what the items are
				$hints = array_values(array_filter(
					$hints,
					fn(string $hint) => strtolower($hint) !== 'array' && self::isTraversable($hint, $traversableTypeHints, $resolveClass),
				));
			}
		} else {
			$hint = self::oneType($type);
			if ($hint === null) {
				return null;
			}

			$hints[] = $hint;
		}

		$expanded = [];
		foreach ($hints as $hint) {
			$expanded = [...$expanded, ...(self::Unofficial[strtolower($hint)] ?? [$hint])];
		}

		$hints = array_values(array_unique($expanded));
		$intersection = $type instanceof Type\IntersectionTypeNode;
		if (
			$hints === []
			|| ($intersection && ($nullable || (count($hints) > 1 && !$php->isAtLeast('8.1'))))
		) {
			return null;
		}

		foreach ($hints as $i => $hint) {
			$hints[$i] = strtolower($hint) === 'true' && !$php->isAtLeast('8.2') ? 'bool' : $hint;
			if (
				!self::isValid($hints[$i], $place, $php, count($hints) > 1)
				|| in_array($hint, $templates, strict: true)
			) {
				return null;
			}
		}

		if (in_array('mixed', array_map('strtolower', $hints), strict: true)) {
			return 'mixed';
		}

		return match (true) {
			$intersection => implode('&', $hints),
			$nullable && count($hints) === 1 => '?' . $hints[0],
			$nullable => implode('|', $hints) . '|null',
			default => implode('|', $hints),
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


	private static function isPlain(Type\TypeNode $type): bool
	{
		if ($type instanceof Type\UnionTypeNode) {
			foreach ($type->types as $member) {
				if (!self::isPlain($member)) {
					return false;
				}
			}

			return true;
		}

		return match (true) {
			$type instanceof Type\IdentifierTypeNode => !in_array(strtolower($type->name), ['static', '$this'], strict: true),
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
			fn(string $member) => in_array(strtolower($member), self::Builtin, strict: true) ? strtolower($member) : $member,
			explode('|', $type),
		);
		sort($members);
		return implode('|', $members);
	}


	/** The native name behind one type node, null for a node that is not one type. */
	private static function oneType(Type\TypeNode $type): ?string
	{
		return match (true) {
			$type instanceof Type\IdentifierTypeNode => self::identifier($type->name),
			$type instanceof Type\GenericTypeNode => self::identifier($type->type->name),
			$type instanceof Type\ThisTypeNode => 'static',
			$type instanceof Type\CallableTypeNode => $type->identifier->name,
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


	private static function identifier(string $name): string
	{
		$lower = strtolower($name);
		return match (true) {
			isset(self::Aliases[$lower]) => self::Aliases[$lower],
			str_ends_with($lower, '-string') => 'string',
			in_array($lower, self::Builtin, strict: true) => $lower,
			default => $name,
		};
	}


	private static function isValid(string $hint, string $place, PhpVersion $php, bool $inUnion): bool
	{
		$lower = strtolower($hint);
		return match ($lower) {
			'object', 'mixed', 'iterable', 'self', 'parent' => true,
			'static', 'void' => $place === self::Return,
			'never' => $place === self::Return && $php->isAtLeast('8.1'),
			'null', 'true' => $php->isAtLeast('8.2'),
			'false' => $inUnion || $php->isAtLeast('8.2'),
			'callable' => $place !== self::Property,
			default => in_array($lower, self::Simple, strict: true)
				|| (!in_array($lower, self::PseudoTypes, strict: true) && preg_match('~^\\\?[A-Za-z_\x80-\xff][\w\x80-\xff\\\]*$~', $hint) === 1),
		};
	}


	/**
	 * Whether the native type is iterable, or a class configured as traversable.
	 * @param  list<string>  $traversableTypeHints  lowercased fully qualified names
	 * @param  callable(string): string  $resolveClass
	 */
	public static function isTraversable(string $hint, array $traversableTypeHints, callable $resolveClass): bool
	{
		return in_array(strtolower($hint), self::Iterable, strict: true)
			|| (
				preg_match('~^\\\?[A-Za-z_\x80-\xff][\w\x80-\xff\\\]*$~', $hint) === 1
				&& !in_array(strtolower($hint), self::Builtin, strict: true)
				&& in_array(strtolower(ltrim($resolveClass($hint), '\\')), $traversableTypeHints, strict: true)
			);
	}


	/** Whether the annotation is a bare `array` or `iterable`, saying nothing about the items. */
	public static function isPlainIterable(Type\TypeNode $type): bool
	{
		return $type instanceof Type\IdentifierTypeNode && in_array(strtolower($type->name), self::Iterable, strict: true);
	}
}
