<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * An `if` returning true or false in its block and the opposite in `else` or in the statement right after it
 * returns the condition itself, or its negation. Fixed when the condition is a boolean by its form and no comment
 * is inside, otherwise only reported.
 */
#[RuleInfo(
	'dresscode/useless-if-condition-with-return',
	Stage::Structure,
	description: 'Replaces an if returning true or false with a return of the condition',
)]
final class UselessIfConditionWithReturnRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			|| !($list = $node->parent) instanceof NodeList
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
			$tail = $list->getItems()[$list->indexOf($node) + 1] ?? null;
			$elseValue = $tail instanceof ReturnNode && $tail->expression instanceof BooleanNode
				? $tail->expression->value
				: null;
		}

		$last = ($tail ?? $node)->getLastToken();
		if ($elseValue === null || $elseValue === $ifValue || $last === null) {
			return;
		}

		$fixable = !($node->getFirstToken()?->hasCommentUpTo($last) ?? true) && NodeHelpers::isBoolean($node->condition);
		if (!$context->report($node, 'Useless condition, the condition itself is the result') || !$fixable) {
			return;
		}

		if ($ifValue) {
			$expr = clone $node->condition;
			$expr->setEdgeTrivia([], []);
		} else {
			$expr = NodeHelpers::negate($node->condition);
		}

		$return = (new Parser)->parseStatement('return 0;');
		assert($return instanceof ReturnNode && $return->expression !== null);
		$return->expression->replaceWith($expr);
		$node->replaceWith($return);
		if ($tail) {
			$tail->getFirstToken()?->setBlankLinesBefore(0);
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
