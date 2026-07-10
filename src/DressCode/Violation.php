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
