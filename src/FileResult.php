<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * Outcome of processing one file: the violations, the fixed text and what it still violates, and possibly a
 * syntax error.
 */
final class FileResult
{
	public bool $changed {
		get => $this->output !== null && $this->output !== $this->code;
	}


	public function __construct(
		public readonly string $path,
		/** the text that was processed */
		public readonly string $code,
		/** the text after the fixes; null when the file could not be parsed or failed */
		public readonly ?string $output,
		/** @var list<Violation>  what the code violates, positioned in the code */
		public readonly array $violations = [],
		/** @var list<string> */
		public readonly array $warnings = [],
		/** syntax error that prevented processing */
		public readonly ?string $syntaxError = null,
		public readonly ?int $syntaxErrorLine = null,
		public readonly int $passes = 0,
		/** a rule failed, the rules did not converge, the file changed before it was written or it could not be read or written; the result was thrown away */
		public readonly ?string $failure = null,
		/** the page of the manual that says more about the failure, as `page#anchor` */
		public readonly ?string $failureDocs = null,
		/** @var list<Violation>  what the output still violates, positioned in the output: what a check of it reports */
		public readonly array $remaining = [],
		/** the fixed text was written back to the file */
		public private(set) bool $written = false,
	) {
	}


	/** @internal the run wrote the fixed text back */
	public function markWritten(): void
	{
		$this->written = true;
	}
}
