<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use PhpSyntax\Visibility;


/**
 * The method a declaration overrides as its class or interface declares it natively, and where the declaration
 * departs from it.
 */
final readonly class OverriddenSignature
{
	public function __construct(
		/** the class or interface that declares the method, fully qualified */
		public string $declaringClass,
		public bool $final,
		public bool $static,
		/** public or protected */
		public Visibility $visibility,
		/** as PHP describes it, as `Parameter::$type` is; null where the method declares none */
		public ?string $returnType,
		/** the declaration returns what the method does not: it declares no type where the method does, or a wider one */
		public bool $returnWidened,
		/** @var list<Parameter> */
		public array $parameters,
		/** @var list<int>  the positions where the parameter of the declaration takes less than that of the method */
		public array $narrowedParameters,
	) {
	}
}
