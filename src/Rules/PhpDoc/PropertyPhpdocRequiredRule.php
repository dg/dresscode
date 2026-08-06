<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\Member\PropertyNode;


/**
 * A property is documented with a doc comment, whatever the syntax of a plain one; a plain comment right above
 * it is reported, one separated by a blank line documents nothing.
 */
#[RuleInfo(
	'dresscode/propertyPhpdocRequired',
	Stage::Structure,
	description: 'Reports a plain comment in place of a property doc comment',
)]
final class PropertyPhpdocRequiredRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode || ($first = $node->getFirstToken()) === null) {
			return;
		}

		$comment = null;
		$lineBreaks = 0;
		foreach ($first->leadingTrivia as $trivia) {
			if ($trivia->isComment()) {
				$comment = $trivia;
				$lineBreaks = 0;
			} elseif ($trivia->isLineEnding()) {
				$lineBreaks++;
			}
		}

		if (
			$comment !== null
			&& $lineBreaks < 2
			&& !$comment->inInterpolation
			&& $comment->id !== Trivia::DocComment
		) {
			$context->report($node, 'A property must be documented with a doc comment, not a plain comment', trivia: $comment, fixable: false);
		}
	}
}
