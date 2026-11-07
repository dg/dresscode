<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * A directory of the sample whose files write a decision of the file otherwise than the configuration does, which an
 * override would keep and `dresscode init` does not write.
 * @internal
 */
final readonly class Region
{
	public function __construct(
		public string $directory,
		public string $path,
		/** the value as the configuration writes it */
		public mixed $value,
		/** the files of the directory in the sample writing it */
		public int $files,
		/** the files of the directory in the sample writing one value of the decision */
		public int $measured,
	) {
	}
}
