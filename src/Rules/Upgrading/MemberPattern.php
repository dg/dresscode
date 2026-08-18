<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Violation;


/**
 * A method of a class as the upgrading data of PHP write it: `Class::name($a, true)` is the method called with
 * arguments of that shape, and `Class::name` the method however it is called.
 */
final readonly class MemberPattern
{
	private function __construct(
		/** fully qualified, without a leading backslash */
		public string $class,
		public string $name,
		/** the shape the arguments of a call have to have; null for a key without parentheses, which takes any */
		public ?ArgumentPattern $arguments = null,
	) {
	}


	/** @throws \InvalidArgumentException  saying what is wrong with the key */
	public static function fromKey(string $key): self
	{
		if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)::(\w+)(?:\((.*)\))?$~Ds', trim($key), $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " is not written as `Class::name` or `Class::name(\$argument, ...)`.");
		}

		[, $class, $name, $parentheses] = $m;
		try {
			$arguments = $parentheses === null ? null : ArgumentPattern::parse($parentheses);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " cannot be read: {$e->getMessage()}", previous: $e);
		}

		return new self($class, $name, $arguments);
	}
}
