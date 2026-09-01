<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\Severity;
use PhpSyntax\{Node, Token, Trivia};


/**
 * One call of RuleContext::report() as the pass runner accounts for it: what was reported and where, the
 * revision of the file at the time, whether a comment silenced it, whether its fix is risky or missing, and whether
 * the baseline knows it.
 * @internal
 */
final readonly class Report
{
	public function __construct(
		public Node|Token $at,
		public ?Trivia $trivia,
		public string $message,
		public Severity $severity,
		/** FileNode::$revision when the report was made */
		public int $revision,
		/** a suppression comment silenced it: no violation is recorded and the rule must not fix it */
		public bool $silenced,
		/** the baseline knows it: no violation is recorded, the rule may still fix it */
		public bool $known,
		/** null when a comment silenced it, so that the occurrence was never counted */
		public ?string $fingerprint,
		/** line in the original file */
		public int $line,
		public bool $risky,
		/** the token whose gap before it holds the whitespace the report is about */
		public ?Token $gap = null,
		/** the token opening the line the reported whitespace is counted from */
		public ?Token $follows = null,
		/** what is wrong is the shape of the line the reported token stands on, and the rule writes no whitespace */
		public bool $byLine = false,
		/** the fix puts a line break into the gap or takes one out, opening or closing the line of the token */
		public bool $breaks = false,
		/** the rule has a fix for it; false when it only reports it */
		public bool $fixable = true,
	) {
	}
}
