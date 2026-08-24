<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\{CommentPolicy, Node, Parser, Token, Trivia, TriviaKind};
use PhpSyntax\Nodes\{AttributeGroupNode, NodeList};


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a node removed with one gap
 * left of the two around it, an attribute on a line of its own above a declaration. A rule shipped by a package writes
 * with it too.
 */
final class CodeWriter
{
	/**
	 * Removes a node standing on lines of its own between two others, a member of a class among them, and leaves one
	 * gap where there were two, the narrower one, which `Node::remove()` would add up instead: none after the opening
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


	/**
	 * Writes the attributes above the declaration, each in a group on a line of its own, behind the attributes it
	 * carries already: the first one of a declaration without any takes over what stood in front of it, its doc
	 * comment among it. The code is that of an attribute without `#[]`, its class spelled already.
	 * @param  NodeList<AttributeGroupNode>  $attributes  of the declaration
	 * @param  list<string>  $codes
	 */
	public static function addAttributes(Node $declaration, NodeList $attributes, array $codes, RuleContext $context): void
	{
		// a trivia stands in one place, so each is made anew
		$first = $declaration->getFirstToken();
		$eolText = $context->getStyle()->eol;
		$indentationText = $first?->getIndentation() ?? '';
		$indentation = fn() => new Trivia(TriviaKind::Whitespace, $indentationText);
		$takesOver = $attributes->isEmpty();
		foreach ($codes as $code) {
			$group = (new Parser)->parseFragment(AttributeGroupNode::class, "#[$code]");
			$attributes->append($group);
			if ($first === null) {
				continue;
			} elseif ($takesOver) {
				$group->getFirstToken()?->setLeadingTrivia($first->leadingTrivia);
				$first->setLeadingTrivia([$indentation()]);
				$takesOver = false;
			} else {
				$group->getFirstToken()?->setLeadingTrivia([$indentation()]);
			}

			$group->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $eolText)]);
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
