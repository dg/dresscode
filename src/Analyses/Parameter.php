<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use function in_array;


/**
 * A parameter of a function PHP declares or of a method of a class: its name, the type as PHP writes it, and how it takes the argument.
 */
final readonly class Parameter
{
	public function __construct(
		public string $name,
		/** as PHP writes it, `mixed` for an untyped parameter */
		public string $type,
		public bool $variadic = false,
		public bool $byReference = false,
		/** a variadic parameter is one too, PHP calling the function without it */
		public bool $optional = false,
	) {
	}


	/**
	 * Whether an argument written for the other parameter may stand here: the type takes everything the other
	 * one takes and the argument is taken the same way. Inheritance is out of reach, so a class type is only
	 * ever equal to itself and a rewrite the answer would allow is refused instead.
	 */
	public function canReplace(self $other): bool
	{
		if ($this->byReference !== $other->byReference) {
			return false;
		}

		$types = self::split($this->type);
		return in_array('mixed', $types, true)
			|| array_diff(self::split($other->type), $types) === [];
	}


	/** @return list<string> */
	private static function split(string $type): array
	{
		$types = explode('|', strtolower(ltrim($type, '?')));
		if (str_starts_with($type, '?')) {
			$types[] = 'null';
		}

		// PHP passes an int where a float is declared, and nowhere else does it widen
		if (in_array('float', $types, true)) {
			$types[] = 'int';
		}

		return $types;
	}
}
