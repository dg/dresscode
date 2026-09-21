<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use PHPStan\PhpDocParser\Ast\ConstExpr\{ConstExprFloatNode, ConstExprIntegerNode, ConstExprStringNode, ConstFetchNode};
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\{DoctrineAnnotation, DoctrineArray};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use function in_array;


/**
 * The arguments of a Doctrine annotation written as those of the attribute or the instantiation that stands for it,
 * `("/blog", name="blog", methods={"GET"})` as `('/blog', name: 'blog', methods: ['GET'])`: a string in apostrophes,
 * a number, `true`, `false` and `null`, a constant and `Class::NAME` as written, `{}` as an array with its keys,
 * and a nested annotation as `new Class(...)`, its class as the callback spells it. Only one argument may be
 * positional and it has to come first, since Doctrine makes a list of several; anything else is no code.
 * @internal
 */
final class AnnotationArguments
{
	/**
	 * The arguments as code without the parentheses; null for an annotation PHP cannot write so.
	 * @param  \Closure(string): string  $spellClass  the name of a nested annotation as written => the class written
	 */
	public static function write(DoctrineAnnotation $annotation, \Closure $spellClass): ?string
	{
		$arguments = [];
		foreach ($annotation->arguments as $index => $argument) {
			$value = self::writeValue($argument->value, $spellClass);
			if ($value === null || ($argument->key === null && $index > 0)) {
				return null;
			}

			$arguments[] = ($argument->key === null ? '' : "{$argument->key->name}: ") . $value;
		}

		return implode(', ', $arguments);
	}


	/** @param  \Closure(string): string  $spellClass */
	private static function writeValue(mixed $value, \Closure $spellClass): ?string
	{
		return match (true) {
			$value instanceof ConstExprStringNode => self::writeString($value->value),
			$value instanceof ConstExprIntegerNode, $value instanceof ConstExprFloatNode => $value->value,
			$value instanceof IdentifierTypeNode => in_array(strtolower($value->name), ['true', 'false', 'null'], true)
				? strtolower($value->name)
				: (preg_match('~^\\\\?[a-z_]\w*(\\\\\w+)*$~iD', $value->name) ? $value->name : null),
			$value instanceof ConstFetchNode => ($value->className === '' ? '' : "$value->className::") . $value->name,
			$value instanceof DoctrineArray => self::writeArray($value, $spellClass),
			$value instanceof DoctrineAnnotation => self::writeInstantiation($value, $spellClass),
			default => null,
		};
	}


	/** The string in apostrophes, a backslash doubled only where it would escape what follows it: `'\d+'`, `'it\'s'`. */
	private static function writeString(string $value): string
	{
		return "'" . preg_replace('~\\\\(?=[\\\\\']|$)|\'~', '\\\\$0', $value) . "'";
	}


	/** @param  \Closure(string): string  $spellClass */
	private static function writeArray(DoctrineArray $array, \Closure $spellClass): ?string
	{
		$items = [];
		foreach ($array->items as $item) {
			$value = self::writeValue($item->value, $spellClass);
			$key = $item->key === null ? '' : self::writeValue($item->key, $spellClass);
			if ($value === null || $key === null) {
				return null;
			}

			$items[] = ($key === '' ? '' : "$key => ") . $value;
		}

		return '[' . implode(', ', $items) . ']';
	}


	/** @param  \Closure(string): string  $spellClass */
	private static function writeInstantiation(DoctrineAnnotation $annotation, \Closure $spellClass): ?string
	{
		$arguments = self::write($annotation, $spellClass);
		return $arguments === null ? null : 'new ' . $spellClass(ltrim($annotation->name, '@')) . "($arguments)";
	}
}
