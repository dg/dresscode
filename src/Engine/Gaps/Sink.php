<?php declare(strict_types=1);

namespace DressCode\Engine\Gaps;

use DressCode\Line;
use DressCode\Rule;
use DressCode\Space;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;


/**
 * Takes what the engine of the gaps decided about a gap: the claim that won a component, as the rule that made
 * it, what it asks for, what it was made for, the reason it gives, the construct its closure decided about and
 * its side, together with what the gap holds. The fixer of a pass reports and fixes, the survey of the whitespace fuzz records.
 * @internal
 */
interface Sink
{
	/**
	 * @param array{Rule, Line, Node|Token, ?string, ?Node, string} $claim
	 * @param ?Token $previous  null before the first token of the file, whose gap is the open tag
	 * @param ?Space $space  what the whitespace must be when the two tokens come to share a line
	 * @param bool $broken  whether a line break stands in the gap
	 */
	public function line(array $claim, ?Token $previous, Token $token, ?Space $space, bool $broken): void;

	/** @param array{Rule, Space, Node|Token, ?string, ?Node, string} $claim */
	public function space(array $claim, Token $previous, Token $token, string $found): void;

	/**
	 * @param array{Rule, int|array{int, ?int}, Node|Token, ?string, ?Node, string} $claim  the claim the count violates, when it does
	 * @param array{int, ?int} $range  what both sides allow
	 * @param int $from  where the counted line endings begin in the leading trivia of the token
	 * @param ?Trivia $below  the comment the counted line endings stand below, null when they are the gap's own
	 */
	public function blankLines(array $claim, Token $token, array $range, int $from, int $found, ?Trivia $below): void;
}
