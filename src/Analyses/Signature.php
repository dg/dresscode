<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * The method a declaration overrides as its class or interface declares it natively, and where the declaration
 * departs from it. A type is written the way PHP describes it, a class fully qualified without a leading backslash
 * and a nullable type as a union with null.
 */
final readonly class Signature
{
	public function __construct(
		/** the class or interface that declares the method, fully qualified */
		public string $class,
		public bool $final,
		public bool $static,
		/** public or protected */
		public string $visibility,
		/** null where the method declares none */
		public ?string $returnType,
		/** the declaration returns what the method does not: it declares no type where the method does, or a wider one */
		public bool $returnWidened,
		/** @var list<SignatureParameter> */
		public array $parameters,
	) {
	}
}
