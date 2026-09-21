<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;


/**
 * What attributeForAnnotation writes instead of an annotation or an attribute.
 * @internal
 */
final readonly class AttributeTarget
{
	private const Pattern = '~^\\\\?(\w+(?:\\\\\w+)*)(\(.*\))?$~Ds';

	private const NamespacePattern = '~^\\\\?(\w+(?:\\\\\w+)*)\\\\\*$~D';


	public function __construct(
		/** fully qualified, without a leading backslash */
		public string $class,
		/** with their parentheses, or empty */
		public string $arguments = '',
		/** a namespace of the map gave the class, which may therefore not exist */
		public bool $fromNamespace = false,
	) {
	}


	/** The attribute written as `Class` or `Class(arguments)`, its class fully qualified; null for code of another shape. */
	public static function fromCode(string $code): ?self
	{
		return preg_match(self::Pattern, $code, $m) ? new self($m[1], $m[2] ?? '') : null;
	}


	/** The namespace of attributes written as `Namespace\*`; null for code of another shape. */
	public static function findNamespace(string $code): ?string
	{
		return preg_match(self::NamespacePattern, $code, $m) ? $m[1] : null;
	}
}
