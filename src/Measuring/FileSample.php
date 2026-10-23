<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use function count;


/**
 * The files a decision is measured on: every k-th of the scope, so that the same tree always gives the same
 * sample. The cost of measuring is bytes and not files, so the sample has a budget of both, and a file of a hundred
 * kilobytes is a generator's anyway, whatever its head says.
 * @internal
 */
final readonly class FileSample
{
	/** the largest sample: every k-th of the sorted files up to this many, and up to this many bytes */
	public const MaxFiles = 300;
	public const MaxBytes = 2_000_000;

	/** a file larger than this is left out whatever room the sample has */
	public const MaxFileSize = 100_000;

	/** how far into a file the mark of a generator is looked for */
	private const HeadLength = 1024;

	/** what a generator writes into the head of what it wrote */
	private const GeneratedPattern = '~@generated\b|\b(?:auto-?generated|automatically generated|do not (?:edit|modify))\b~i';


	private function __construct(
		/** @var list<string>  the sample, relative to the root */
		public array $files,
		/** files of the scope left out because they are too large or the sample had no room left */
		public int $oversized,
	) {
	}


	/** @param  list<string>  $files  the scope, sorted, relative to the root */
	public static function pick(string $root, array $files, int $maxFiles = self::MaxFiles, int $maxBytes = self::MaxBytes): self
	{
		$step = max(1, (int) ceil(count($files) / $maxFiles));
		$picked = [];
		$bytes = $oversized = 0;
		foreach ($files as $index => $file) {
			if ($index % $step !== 0) {
				continue;
			}

			$size = (int) @filesize("$root/$file"); // @ file may have been deleted meanwhile
			if ($size > self::MaxFileSize || $bytes + $size > $maxBytes) {
				$oversized++;
			} else {
				$picked[] = $file;
				$bytes += $size;
			}
		}

		return new self($picked, $oversized);
	}


	/** Whether the head of the file says a generator wrote it. */
	public static function isGenerated(string $file): bool
	{
		$head = @file_get_contents($file, length: self::HeadLength); // @ file may have been deleted meanwhile
		return $head !== false && preg_match(self::GeneratedPattern, $head) === 1;
	}
}
