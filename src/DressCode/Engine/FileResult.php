<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Violation;


/**
 * Outcome of processing one file: the violations, the fixed text and possibly a syntax error.
 */
final class FileResult
{
	/** the fixed text was written back to the file */
	public bool $written = false;


	/**
	 * @param ?string $output  the text after the fixes; null when the file could not be parsed
	 * @param list<Violation> $violations
	 * @param list<string> $warnings
	 * @param ?string $error  syntax error that prevented processing
	 * @param ?string $failure  a rule failed or the rules did not converge; the result was thrown away
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $code,
		public readonly ?string $output,
		public readonly array $violations = [],
		public readonly array $warnings = [],
		public readonly ?string $error = null,
		public readonly ?int $errorLine = null,
		public readonly int $passes = 0,
		public readonly ?string $failure = null,
	) {
	}


	public function isChanged(): bool
	{
		return $this->output !== null && $this->output !== $this->code;
	}


	/** @return list<Violation> */
	public function getUnfixedViolations(): array
	{
		return array_values(array_filter($this->violations, fn(Violation $v) => !$v->fixable));
	}
}
