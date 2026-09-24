<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{Values, Violation};
use PhpSyntax\{Builder, ParseException};
use PhpSyntax\Nodes\AttributeGroupNode;


/**
 * An entry of the map of the attributes written for a convention: what a class declares for a library to read, and the
 * attribute the library reads instead.
 * @internal
 */
final readonly class AttributeForMemberEntry
{
	public const Path = 'upgrading.libraries.attributeForMember';

	private const ClassPattern = '\\\\?(\w+(?:\\\\\w+)*)';


	public function __construct(
		public AttributeForMemberKind $kind,
		/** the ancestor whose member the class declares, or the interface it implements */
		public string $class,
		/** the name of the member, empty for an interface */
		public string $name,
		public string $attribute,
		/** with their parentheses, or empty */
		public string $arguments,
		/** @var ?array{mixed}  the default of the property the entry stands for alone, wrapped so that null is one too */
		public ?array $literal = null,
	) {
	}


	/**
	 * The entries of the map as the project resolved it, an entry for a default before one for any value of the same
	 * property.
	 * @return list<self>
	 */
	public static function fromValues(Values $values): array
	{
		$entries = [];
		foreach ($values->readMap(self::Path) as $key => $value) {
			$entries[] = self::fromEntry((string) $key, $value);
		}

		usort($entries, fn(self $a, self $b) => ($b->literal !== null) <=> ($a->literal !== null));
		return $entries;
	}


	/**
	 * The entry of the map, its key the member or the interface and its value the attribute.
	 * @throws \InvalidArgumentException  saying what of it is not written as the map takes it
	 */
	public static function fromEntry(string $key, string $value): self
	{
		if (!preg_match('~^' . self::ClassPattern . '(\(.*\))?$~Ds', trim($value), $attribute)) {
			throw new \InvalidArgumentException('The attribute ' . Violation::formatCode($value) . ' for ' . Violation::formatCode($key) . ' is not written as `Class` or `Class(arguments)`.');
		}

		$arguments = $attribute[2] ?? '';
		try {
			(new Builder)->fragment(AttributeGroupNode::class, "#[$attribute[1]$arguments]");
		} catch (ParseException $e) {
			throw new \InvalidArgumentException('The attribute ' . Violation::formatCode($value) . ' for ' . Violation::formatCode($key) . " does not read as an attribute: {$e->getMessage()}", previous: $e);
		}

		$key = trim($key);
		return match (true) {
			(bool) preg_match('~^' . self::ClassPattern . '::\$(\w+)(?:\s*=\s*(.+))?$~Ds', $key, $m) => new self(
				AttributeForMemberKind::Property,
				$m[1],
				$m[2],
				$attribute[1],
				$arguments,
				isset($m[3]) ? [self::readLiteral($m[3], $key)] : null,
			),
			(bool) preg_match('~^' . self::ClassPattern . '::(\w+)\(\)$~D', $key, $m) => new self(AttributeForMemberKind::Method, $m[1], $m[2], $attribute[1], $arguments),
			(bool) preg_match('~^' . self::ClassPattern . '$~D', $key, $m) => new self(AttributeForMemberKind::Interface, $m[1], '', $attribute[1], $arguments),
			default => throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " is not written as `Class::\$name`, `'Class::\$name = literal'`, `Class::method()` or `Interface`."),
		};
	}


	/** @throws \InvalidArgumentException */
	private static function readLiteral(string $code, string $key): mixed
	{
		try {
			$expression = (new Builder)->expression($code);
		} catch (ParseException) {
			$expression = null;
		}

		return $expression?->hasValue()
			? $expression->toValue()
			: throw new \InvalidArgumentException('The default in ' . Violation::formatCode($key) . ' is no literal.');
	}
}
