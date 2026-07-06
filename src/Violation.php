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
		/** the rule fixed it */
		public bool $fixable,
		/** reported on a tree already changed by another rule, on code the user has not seen */
		public bool $followUp,
		/** stable identity for baselines: rule, message, normalized line content and the occurrence index */
		public string $fingerprint,
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
			'fixable' => $this->fixable,
			'followUp' => $this->followUp,
			'fingerprint' => $this->fingerprint,
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
			(bool) $data['fixable'],
			(bool) $data['followUp'],
			(string) $data['fingerprint'],
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
