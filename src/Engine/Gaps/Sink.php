<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine\Gaps;

use DressCode\{Line, Space};
use PhpSyntax\{Token, Trivia};


/**
 * Takes what the engine of the gaps decided about a gap, the claim that won a component, together with what the
 * gap holds. The fixer of a pass reports and fixes, the survey of the whitespace fuzz records.
 * @internal
 */
interface Sink
{
	/**
	 * @param DecidedClaim<Line> $claim
	 * @param ?Token $previous  null before the first token of the file, whose gap is the open tag
	 * @param ?Space $space  what the whitespace must be when the two tokens come to share a line
	 * @param bool $breaksLine  whether a line break stands in the gap
	 */
	function acceptLine(DecidedClaim $claim, ?Token $previous, Token $token, ?Space $space, bool $breaksLine): void;

	/** @param DecidedClaim<Space> $claim */
	function acceptSpace(DecidedClaim $claim, Token $previous, Token $token, string $found): void;

	/**
	 * @param DecidedClaim<int|array{int, ?int}> $claim  the claim the count violates, when it does
	 * @param array{int, ?int} $range  what both sides allow, or the narrower side where they exclude each other
	 * @param int $from  where the counted line endings begin in the leading trivia of the token
	 * @param ?Trivia $below  the comment the counted line endings stand below, null when they are the gap's own
	 */
	function acceptBlankLines(DecidedClaim $claim, Token $token, array $range, int $from, int $found, ?Trivia $below): void;
}
