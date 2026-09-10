<?php declare(strict_types=1);

namespace DressCode;

use function is_array;


/**
 * Outcome of processing one file: the violations, the fixed text and possibly a syntax error.
 */
final class FileResult
{
	/** the fixed text was written back to the file */
	public bool $written = false;

	/** the content was known to be clean, so it was not processed */
	public bool $cached = false;


	public function __construct(
		public readonly string $path,
		public readonly string $code,
		/** the text after the fixes; null when the file could not be parsed */
		public readonly ?string $output,
		/** @var list<Violation> */
		public readonly array $violations = [],
		/** @var list<string> */
		public readonly array $warnings = [],
		/** syntax error that prevented processing */
		public readonly ?string $error = null,
		public readonly ?int $errorLine = null,
		public readonly int $passes = 0,
		/** a rule failed or the rules did not converge; the result was thrown away */
		public readonly ?string $failure = null,
		/** @var list<string> fingerprints of the violations the baseline silenced, for the run to count */
		public readonly array $baselined = [],
	) {
	}


	public function isChanged(): bool
	{
		return $this->output !== null && $this->output !== $this->code;
	}


	/**
	 * The result as data for another process; the output is sent only when it differs from the code,
	 * base64-encoded since it need not be UTF-8.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'path' => $this->path,
			'output' => $this->output === $this->code ? true : ($this->output === null ? null : base64_encode($this->output)),
			'violations' => array_map(fn(Violation $v) => $v->toArray(), $this->violations),
			'warnings' => $this->warnings,
			'error' => $this->error,
			'errorLine' => $this->errorLine,
			'passes' => $this->passes,
			'failure' => $this->failure,
			'baselined' => $this->baselined,
			'written' => $this->written,
		];
	}


	/** @param array<string, mixed> $data  as toArray() made it, for the given code */
	public static function fromArray(array $data, string $code): self
	{
		$output = $data['output'];
		$result = new self(
			(string) $data['path'],
			$code,
			$output === true ? $code : ($output === null ? null : (string) base64_decode((string) $output, strict: true)),
			array_values(array_map(Violation::fromArray(...), is_array($data['violations']) ? $data['violations'] : [])),
			is_array($data['warnings']) ? array_values(array_map('strval', $data['warnings'])) : [],
			$data['error'] === null ? null : (string) $data['error'],
			$data['errorLine'] === null ? null : (int) $data['errorLine'],
			(int) $data['passes'],
			$data['failure'] === null ? null : (string) $data['failure'],
			is_array($data['baselined']) ? array_values(array_map('strval', $data['baselined'])) : [],
		);
		$result->written = (bool) $data['written'];
		return $result;
	}


	/** @return list<Violation> */
	public function getUnfixedViolations(): array
	{
		return array_values(array_filter($this->violations, fn(Violation $v) => !$v->fixable));
	}
}
