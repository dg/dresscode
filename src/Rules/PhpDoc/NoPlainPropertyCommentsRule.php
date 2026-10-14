<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\Member\PropertyNode;


/**
 * A property is documented with a doc comment, whatever the syntax of a plain one; a plain comment right above
 * it is reported, one separated by a blank line documents nothing, and one below a doc comment only adds to it.
 * A comment above properties that follow one another without a blank line heads the group and is left.
 */
#[RuleInfo(Stage::Structure)]
final class NoPlainPropertyCommentsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'phpdoc.property.comment',
			new Words(['phpdoc' => 'a doc comment, never a plain comment']),
			'The comment describing a property, `/** */` and not `//` or `/* */`',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode || ($first = $node->getFirstToken()) === null) {
			return;
		}

		$comment = null;
		$documented = false; // a doc comment stands in the run of comments right above
		$lineBreaks = 0;
		foreach ($first->leadingTrivia as $trivia) {
			if ($trivia->isComment()) {
				$documented = ($documented && $lineBreaks < 2) || $trivia->is(Trivia::DocComment);
				$comment = $trivia;
				$lineBreaks = 0;
			} elseif ($trivia->isLineEnding()) {
				$lineBreaks++;
			}
		}

		// a comment above properties following one another on the next lines heads them all, it documents none
		$next = $node->getNextSibling();
		$heads = $next instanceof PropertyNode && $next->getFirstToken()?->countBlankLinesBefore() === 0;
		if (
			$comment !== null
			&& $lineBreaks < 2
			&& !$comment->inInterpolation
			&& !$documented
			&& !$heads
		) {
			$context->report(
				$node,
				'The property must be documented with a doc comment, not a plain comment.',
				trivia: $comment,
				fixable: false,
			);
		}
	}
}
