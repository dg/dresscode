<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * A parameter of the method a declaration overrides, as Signature has it.
 */
final readonly class SignatureParameter
{
	public function __construct(
		public string $name,
		/** as PHP describes it; null where the parameter declares none */
		public ?string $type,
		public bool $optional,
		public bool $variadic,
		public bool $byReference,
		/** the default as PHP code; null where the parameter has none or it is no value to write */
		public ?string $default,
		/** the parameter the declaration has at its position takes less than this one does */
		public bool $narrowed,
	) {
	}
}
