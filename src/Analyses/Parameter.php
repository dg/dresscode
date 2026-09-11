<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * A parameter of a function PHP or the file declares, or of a method of a class: its name, the type as PHP describes
 * it, a class fully qualified without a leading backslash and a nullable type as a union with null, and how it takes
 * the argument.
 */
final readonly class Parameter
{
	public function __construct(
		public string $name,
		/** null for a parameter that declares none */
		public ?string $type = null,
		/** a variadic parameter is one too, PHP calling the function without it */
		public bool $optional = false,
		public bool $variadic = false,
		public bool $byReference = false,
		/**
		 * the default as PHP code; null for a parameter without one, for one whose default is no value to write, and for
		 * a function of PHP whose versions declare different ones
		 */
		public ?string $default = null,
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

		$types = self::split($this->type ?? 'mixed');
		return in_array('mixed', $types, true)
			|| array_diff(self::split($other->type ?? 'mixed'), $types) === [];
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


	/**
	 * The first parameter without a default the arguments leave out, the first `$positional` of them given by
	 * position and the `$named` ones by name.
	 * @param  list<self>  $parameters
	 * @param  list<string>  $named
	 */
	public static function findOmitted(array $parameters, int $positional, array $named): ?self
	{
		return array_find(
			array_slice($parameters, $positional),
			fn(self $parameter) => !$parameter->optional && !in_array($parameter->name, $named, true),
		);
	}
}
