<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\FileResult;


/**
 * What a run keeps of a file once it was reported: the result without its texts and with its violations only counted,
 * so that a large tree does not stay in memory.
 * @internal
 */
final readonly class FileSummary
{
	public function __construct(
		public string $path,
		public ViolationTally $violations,
		public ViolationTally $remaining,
		/** the output differs from the code */
		public bool $changed,
		public bool $written,
		public bool $cached,
		public ?string $syntaxError,
		public ?string $failure,
	) {
	}


	public static function of(FileResult $result): self
	{
		return new self(
			$result->path,
			ViolationTally::of($result->violations),
			ViolationTally::of($result->remaining),
			$result->changed,
			$result->written,
			$result->cached,
			$result->syntaxError,
			$result->failure,
		);
	}
}
