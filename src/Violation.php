<?php declare(strict_types=1);

namespace DressCode;


final readonly class Violation
{
	public function __construct(
		public string $ruleName,
		public string $message,
		/** line in the original file */
		public int $line,
		public ?int $column,
		public Severity $severity,
		/** stable identity for baselines: rule, message, normalized line content and the occurrence index */
		public string $fingerprint,
		/** the fix of this occurrence may change what the code does, so it waits until the run allows one */
		public bool $risky = false,
		/** the run did not allow the fix of this risky occurrence */
		public bool $refused = false,
		/**
		 * fingerprint of the violation of the same file this one follows from: its fix opened, closed or moved
		 * the line the whitespace reported here stands on, so the code the user wrote was not what was found
		 */
		public ?string $derivedFrom = null,
	) {
	}


	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'rule' => $this->ruleName,
			'message' => $this->message,
			'line' => $this->line,
			'column' => $this->column,
			'severity' => $this->severity === Severity::Error ? 'error' : 'warning',
			'risky' => $this->risky,
			'refused' => $this->refused,
			'fingerprint' => $this->fingerprint,
			'derivedFrom' => $this->derivedFrom,
		];
	}


	/** @param array<string, mixed> $data  as toArray() made it */
	public static function fromArray(array $data): self
	{
		return new self(
			(string) $data['rule'],
			(string) $data['message'],
			(int) $data['line'],
			$data['column'] === null ? null : (int) $data['column'],
			$data['severity'] === 'error' ? Severity::Error : Severity::Warning,
			(string) $data['fingerprint'],
			(bool) $data['risky'],
			(bool) $data['refused'],
			$data['derivedFrom'] === null ? null : (string) $data['derivedFrom'],
		);
	}


	/** @param string $lineContent  normalized by normalizeLineContent() */
	public static function createFingerprint(
		string $ruleName,
		string $message,
		string $lineContent,
		int $occurrence,
	): string
	{
		return hash('xxh3', "$ruleName\n$message\n$lineContent\n$occurrence");
	}


	/**
	 * Line content as the fingerprint sees it: trimmed, whitespace collapsed.
	 */
	public static function normalizeLineContent(string $content): string
	{
		return (string) preg_replace('~\s+~', ' ', trim($content));
	}
}
