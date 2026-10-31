<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * A place a check of `dresscode init` reports, kept for what a question shows of the code.
 * @internal
 */
final readonly class Finding
{
	public function __construct(
		/** relative to the root */
		public string $file,
		public int $line,
		public string $message,
		/** whether a fix changes it without a risk */
		public bool $fixable,
		/** @var ?array{int, int}  the offset and the length of what it reports in the code */
		public ?array $span = null,
	) {
	}


	/** `path:line  message` */
	public function describe(): string
	{
		return "$this->file:$this->line  $this->message";
	}
}
