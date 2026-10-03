<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Nodes\Statement\{BlockNode, IfNode, ReturnNode};
use function count;


/**
 * An `if` returning true or false in its block and the opposite in `else` or in the statement right after it
 * returns the condition itself, or its negation. Fixed when what it returns then is a boolean by its form and no
 * comment is inside, otherwise only reported: `!$x` negated is `$x`, which need not be a boolean. Without the
 * types, a variable or a call holding a boolean is not told from one holding anything else.
 */
#[RuleInfo(
	'dresscode/returnForBooleanIf',
	Stage::Structure,
	description: 'Replaces an `if` returning `true` or `false` with a `return` of the condition',
	group: RuleGroup::Cleanup,
)]
final class ReturnForBooleanIfRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			|| !$node->parent instanceof PlainNodeList
			|| !$node->body instanceof BlockNode
			|| !$node->elseifs->isEmpty()
			|| ($ifValue = self::findReturnedLiteral($node->body)) === null
		) {
			return;
		}

		$tail = null;
		if ($node->else) {
			$elseValue = $node->else->body instanceof BlockNode ? self::findReturnedLiteral($node->else->body) : null;
		} else {
			$next = $node->getNextSibling();
			$tail = $next instanceof ReturnNode ? $next : null;
			$elseValue = $tail?->expression instanceof BooleanNode
				? $tail->expression->value
				: null;
		}

		$last = ($tail ?? $node)->getLastToken();
		if ($elseValue === null || $elseValue === $ifValue) {
			return;
		}

		$expr = $ifValue
			? $node->condition->withoutEdgeTrivia()
			: NodeHelpers::negate($node->condition);
		$fixable = !$node->getFirstToken()->hasCommentUpTo($last) && $expr->evaluatesToBoolean();
		if (!$context->report($node, 'Useless condition, the condition itself is the result', fixable: $fixable)) {
			return;
		}

		$node->replaceWith((new Builder)->statement('return $value;', value: $expr));
		if ($tail) {
			$first = $tail->getFirstToken();
			if ($first->startsLine()) {
				$first->setBlankLinesBefore(0, "\n");
			}

			$tail->remove();
		}
	}


	/** The value of a block consisting of `return true;` or `return false;`, null for any other block. */
	private static function findReturnedLiteral(BlockNode $block): ?bool
	{
		$stmt = $block->statements->getItems()[0] ?? null;
		return count($block->statements) === 1 && $stmt instanceof ReturnNode && $stmt->expression instanceof BooleanNode
			? $stmt->expression->value
			: null;
	}
}
