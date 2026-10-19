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


	/** @internal made by the engine */
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
		/**
		 * a rule failed, the rules did not converge, the file changed before it was written or it could not be read or
		 * written; the result was thrown away
		 */
		public readonly ?string $failure = null,
		/** the page of the manual that says more about the failure, as `page#anchor` */
		public readonly ?string $failureDocs = null,
		/** @var list<string> fingerprints of the violations the baseline matched, for the run to count */
		public readonly array $baselined = [],
		/** @var list<Violation>  what the output still violates, positioned in the output: what a check of it reports */
		public readonly array $remaining = [],
		/** the fixed text was written back to the file */
		public private(set) bool $written = false,
		/** the content was known to be clean, so it was not processed */
		public readonly bool $cached = false,
		/**
		 * @var list<array{kind: 'class'|'function'|'const', name: string, alias: ?string, namespace: string}>  the imports
		 * the fixes removed, nothing using them, which the code that was processed may have meant to use
		 */
		public readonly array $removedImports = [],
		/** @var list<class-string<Rule>>  the rules whose fixes changed the text */
		public readonly array $mutatedRules = [],
		/** the violations left out of what the run reports, standing outside the lines it was narrowed to */
		public readonly int $unlisted = 0,
		/** @var list<string>  the decisions the file broke before an edit and still does, left as the file writes them */
		public readonly array $leftDecisions = [],
	) {
	}


	/**
	 * The result reporting only the violations given, the others counted as left out.
	 * @param  list<Violation>  $violations
	 * @param  list<Violation>  $remaining
	 * @param  list<string>  $leftDecisions
	 * @internal
	 */
	public function withListed(array $violations, array $remaining, int $unlisted, array $leftDecisions = []): self
	{
		return new self(
			$this->path, $this->code, $this->output, $violations, $this->warnings, $this->syntaxError,
			$this->syntaxErrorLine, $this->passes, $this->failure, $this->failureDocs, $this->baselined, $remaining,
			$this->written, $this->cached, $this->removedImports, $this->mutatedRules, $unlisted, $leftDecisions,
		);
	}


	/** @internal the run wrote the fixed text back */
	public function markWritten(): void
	{
		$this->written = true;
	}
}
