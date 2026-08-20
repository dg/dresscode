<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use PhpSyntax\{CommentPolicy, Node, Token};


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a node removed with one gap
 * left of the two around it. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/**
	 * Removes a node standing on lines of its own between two others, a member of a class among them, and leaves one
	 * gap where there were two, the narrower one, which Node::remove() would add up instead: none after the opening
	 * brace for the first member and before the closing one for the last. The comments of the node go where the policy
	 * says, to the next token by default.
	 */
	public static function removeBetweenGaps(Node $node, string $eol, CommentPolicy $comments = CommentPolicy::MoveToNextToken): void
	{
		$next = $node->getLastToken()?->getNext();
		$gap = $next !== null && $next->startsLine()
			? min(self::countBlankLines($node->getFirstToken()), self::countBlankLines($next))
			: null;
		$node->remove($comments);
		if ($next !== null && $gap !== null) {
			$next->setBlankLinesBefore($gap, $eol);
		}
	}


	private static function countBlankLines(?Token $token): int
	{
		$count = 0;
		while (($token?->leadingTrivia[$count] ?? null)?->isEndOfLine()) {
			$count++;
		}

		return $count;
	}
}
