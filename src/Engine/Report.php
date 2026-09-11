<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Severity;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;


/**
 * One call of RuleContext::report() as the pass runner accounts for it: what was reported and where, the
 * revision of the file at the time, and whether a comment, the baseline or the risk of the fix denied it.
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
		/** a dresscode:ignore comment silenced it */
		public bool $silenced,
		/** null when a comment silenced it, so that the occurrence was never counted */
		public ?string $fingerprint,
		/** line in the original file */
		public int $line,
		public bool $risky,
		/** the token whose gap before it holds the whitespace the report is about */
		public ?Token $gap = null,
		/** the token opening the line the reported whitespace is counted from */
		public ?Token $follows = null,
		/** the fix puts a line break into the gap or takes one out, opening or closing the line of the token */
		public bool $breaks = false,
	) {
	}
}
