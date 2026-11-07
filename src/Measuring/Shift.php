<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * A decision the configuration that exists says otherwise than the code does now, both as the configuration writes them.
 * @internal
 */
final readonly class Shift
{
	public function __construct(
		public ShiftKind $kind,
		public string $path,
		/** what the configuration says; null where it says nothing */
		public ?string $current,
		/** what init writes now, or for a decision the code holds to the standard in, what the standard says */
		public string $measured,
		/** what the measured value rests on */
		public string $comment = '',
	) {
	}
}
