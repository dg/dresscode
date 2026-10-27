<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\Helpers;
use DressCode\Measuring\FileSample;


/**
 * The files an upgrade goes through, and those of the scope left out because a generator wrote them.
 * @internal
 */
final readonly class UpgradeFiles
{
	public function __construct(
		/** @var list<string>  relative to the root */
		public array $files,
		/** @var list<string>  relative to the root */
		public array $generated,
	) {
	}


	/** @param  list<string>  $files  the scope, relative to the root, or absolute when outside it */
	public static function fromScope(string $root, array $files): self
	{
		$generated = array_values(array_filter(
			$files,
			fn(string $file) => FileSample::isGenerated(Helpers::toAbsolutePath($file, $root)),
		));
		return new self(array_values(array_diff($files, $generated)), $generated);
	}
}
