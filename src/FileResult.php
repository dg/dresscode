<?php declare(strict_types=1);

namespace DressCode;


/**
 * Outcome of processing one file: the violations, the fixed text and what it still violates, and possibly a
 * syntax error.
 */
final class FileResult
{
	/** the fixed text was written back to the file */
	public bool $written = false;

	/** whether the output differs from the code, kept by a result without its texts */
	private ?bool $changed = null;


	public function __construct(
		public readonly string $path,
		/** the text that was processed; empty in the results a run keeps, see withoutTexts() */
		public readonly string $code,
		/** the text after the fixes; null when the file could not be parsed or failed, empty in the results a run keeps */
		public readonly ?string $output,
		/** @var list<Violation>  what the code violates, positioned in the code */
		public readonly array $violations = [],
		/** @var list<string> */
		public readonly array $warnings = [],
		/** syntax error that prevented processing */
		public readonly ?string $error = null,
		public readonly ?int $errorLine = null,
		public readonly int $passes = 0,
		/** a rule failed, the rules did not converge or the file changed before it was written; the result was thrown away */
		public readonly ?string $failure = null,
		/** @var list<string> fingerprints of the violations the baseline silenced, for the run to count */
		public readonly array $baselined = [],
		/** @var list<Violation>  what the output still violates, positioned in the output: what a check of it reports */
		public readonly array $remaining = [],
	) {
	}


	public function isChanged(): bool
	{
		return $this->changed ?? ($this->output !== null && $this->output !== $this->code);
	}


	/**
	 * The result without its code and output, whether it changed kept: what a run holds of a file once it was
	 * reported, so that a large tree does not stay in memory.
	 */
	public function withoutTexts(): self
	{
		$result = new self(
			$this->path,
			'',
			$this->output === null ? null : '',
			$this->violations,
			$this->warnings,
			$this->error,
			$this->errorLine,
			$this->passes,
			$this->failure,
			$this->baselined,
			$this->remaining,
		);
		$result->changed = $this->isChanged();
		$result->written = $this->written;
		return $result;
	}
}
