<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, InvalidTagValueNode, PhpDocTagNode};
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\Member\PropertyNode;
use function count;


/**
 * A `@var` or `@see` without content in a property doc comment is reported; the other tags may stand alone,
 * as `@internal` and `@deprecated` do.
 */
#[RuleInfo(
	'dresscode/noEmptyVarAnnotations',
	Stage::Structure,
	description: 'Reports a `@var` or `@see` without content in a property doc comment',
	group: RuleGroup::Correctness,
)]
final class NoEmptyVarAnnotationsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode) {
			return;
		}

		$comments = $node->getLeadingComments();
		$comment = $comments[count($comments) - 1] ?? null;

		if ($comment === null || $comment->inInterpolation || !$comment->is(Trivia::DocComment)) {
			return;
		}

		foreach ($context->getAnalysis(PhpDoc::class)->parse($comment)->children as $child) {
			$name = $child instanceof PhpDocTagNode ? strtolower($child->name) : null;
			$empty = $child instanceof PhpDocTagNode
				&& (
					$child->value instanceof InvalidTagValueNode
					|| ($child->value instanceof GenericTagValueNode && trim($child->value->value) === '')
				);
			if ($empty && ($name === '@var' || $name === '@see')) {
				$context->report($node, "The `$name` annotation has no content", trivia: $comment, fixable: false);
			}
		}
	}
}
