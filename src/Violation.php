<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\Helpers;


final readonly class Violation
{
	public function __construct(
		/** the path of the decision the occurrence violates */
		public string $decision,
		public string $message,
		/** line in the text the violation was found in: the file as it was read, or the fixed text for what a fix leaves */
		public int $line,
		public ?int $column,
		public Severity $severity,
		/** stable identity for baselines: the decision, the normalized content of the line and the place among the violations of that decision on it */
		public string $fingerprint,
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
	) {
		if ($risk === null && ($refused || $because !== null)) {
			throw new \InvalidArgumentException("Violation of `$decision`: `refused` and `because` belong to a risky violation, but no risk is given.");
		}
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
