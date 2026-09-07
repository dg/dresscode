<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * A parameter of a function PHP declares or of a method of a class: its name, the type as PHP describes it, a class
 * fully qualified without a leading backslash and a nullable type as a union with null, and how it takes the argument.
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
		 * a function of PHP, whose catalog holds none
		 */
		public ?string $default = null,
	) {
	}
}
