<?php declare(strict_types=1);

namespace DressCode;

use function is_array;


/**
 * Outcome of processing one file: the violations, the fixed text and what it still violates, and possibly a
 * syntax error.
 */
final class FileResult
{
	/** the fixed text was written back to the file */
	public bool $written = false;

	/** the content was known to be clean, so it was not processed */
	public bool $cached = false;

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
		$result->cached = $this->cached;
		return $result;
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
			'remaining' => array_map(fn(Violation $v) => $v->toArray(), $this->remaining),
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
			array_values(array_map(Violation::fromArray(...), is_array($data['remaining']) ? $data['remaining'] : [])),
		);
		$result->written = (bool) $data['written'];
		return $result;
	}
}
