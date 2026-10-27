<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\Helpers;
use function strlen;


final readonly class Violation
{
	public function __construct(
		/** the path of the decision the occurrence violates */
		public string $decision,
		public string $message,
		/** line in the text the violation was found in: the file as it was read, or the fixed text for what a fix leaves */
		public int $line,
		/**
		 * column of the reported token in that line, from 1, in characters, a tab being one; null where the report is
		 * about whitespace or a comment, or the token has no place in that text, a fix having written it
		 */
		public ?int $column,
		public Severity $severity,
		/**
		 * stable identity for baselines: the decision, the normalized content of the line and the place among the
		 * violations of that decision on it
		 */
		public string $fingerprint,
		/**
		 * the run has a fix for this occurrence, whether or not it makes it (a refused risky fix is fixable); false where
		 * the rule only reports it, or the project reports the decision only (`reportOnly`)
		 */
		public bool $fixable = true,
		/** the fix of this occurrence may change what the code does, so it waits until the run allows one; what would decide */
		public ?Risk $risk = null,
		/** the run did not allow the fix of this risky occurrence */
		public bool $refused = false,
		/** what may go wrong at this risky occurrence, where the risk alone does not say it */
		public ?string $because = null,
		/**
		 * fingerprint of the violation of the same file this one follows from: its fix opened, closed or moved
		 * the line the whitespace reported here stands on, so the code the user wrote was not what was found
		 */
		public ?string $derivedFrom = null,
		/**
		 * byte offset of the reported span in the text the violation was found in, from 0: the node or token reported,
		 * the trivia the report is about, or the empty place of whitespace that is missing; null where the span has no
		 * place in that text, a fix having written or rewritten where it starts or ends
		 */
		public ?int $offset = null,
		/** length of the span in bytes, 0 for the place of missing whitespace */
		public ?int $length = null,
		/**
		 * @var ?array{int, int}  the first and the last line of the file as read the reported code stands on, of what a fix
		 * leaves too, code a fix wrote taking the lines of the code beside it; null where the fixed text was read anew
		 */
		public ?array $originalLines = null,
		/**
		 * the section of the upgrading data of a package whose entry the occurrence is of, null where the project wrote
		 * the entry or the rule reads no map
		 */
		public ?Config\Layer $source = null,
	) {
		if ($risk === null && ($refused || $because !== null)) {
			throw new \InvalidArgumentException("Violation of `$decision`: `refused` and `because` belong to a risky violation, but no risk is given.");
		} elseif ($risk !== null && !$fixable) {
			throw new \InvalidArgumentException("Violation of `$decision`: a risk belongs to a fix, but the violation is not fixable.");
		}
	}


	/**
	 * The line and the column where the span starts, both from 1, the column in characters as `$column` counts them;
	 * null where the violation has no span.
	 * @param  string  $text  the text the violation was found in
	 * @return ?array{int, int}
	 */
	public function findSpanStart(string $text): ?array
	{
		return $this->offset === null ? null : self::findPosition($text, $this->offset);
	}


	/**
	 * The line and the column where the span ends, the first character after it, as `findSpanStart()` counts them.
	 * @return ?array{int, int}
	 */
	public function findSpanEnd(string $text): ?array
	{
		return $this->offset === null || $this->length === null ? null : self::findPosition($text, $this->offset + $this->length);
	}


	/** @return array{int, int} */
	private static function findPosition(string $text, int $offset): array
	{
		$before = substr($text, 0, $offset);
		$line = preg_match_all('~\r\n|\r|\n~', $before) + 1;
		$lineStart = max(strrpos($before, "\n"), strrpos($before, "\r"));
		$current = $lineStart === false ? $before : substr($before, $lineStart + 1);
		return [$line, strlen($current) - preg_match_all('~[\x80-\xBF]~', $current) + 1];
	}


	/**
	 * Returns the code as a message writes it, a code span of Markdown: in backticks, or where the code holds a backtick
	 * of its own, in a run of them longer than any inside, padded with a space where the code starts or ends with one;
	 * empty code as the empty string of PHP. A name cannot hold a backtick, so a message writes it in backticks
	 * directly; this is for an expression, a string, a comment or a text of the configuration.
	 */
	public static function formatCode(string $code): string
	{
		return Helpers::formatCode($code);
	}
}
