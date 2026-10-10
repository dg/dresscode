<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, DecisionKind, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\{FunctionLikeNode, PlainNodeList, StatementNode};
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\Statement\{BlockNode, DoWhileNode, ForeachNode, ForNode, IfNode, WhileNode};
use PhpSyntax\Nodes\Type\NamedTypeNode;
use function count;


/**
 * Leave early instead of nesting: an `if` that is the last statement of a function or loop body and does
 * not leave itself turns into a guard, `if (!cond) { return; }` or `continue;`, with its statements following
 * it; an `if` whose `else` leaves while the `if` branch does not swaps the two, so that the leaving branch
 * comes first and the rest needs no else. A trailing `if` in a function with a return type other than void stays,
 * because a bare return would not do there; a comment the fix would move into the guard or drop is only
 * reported. At the top of a file, a body declaring a function or a class keeps its shape too, because out of
 * the if PHP would bind the declaration before the code runs; in a function or a loop it binds it where it
 * stands. The moved statements keep their indentation, which is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Structure)]
final class EarlyExitForTrailingIfRule extends NodeRule
{
	private int $minStatements = 2;


	public static function getDecisions(): array
	{
		return [
			new Decision('controlFlow.trailingIf', Domain::state(), 'An `if` ending a function or a loop body becomes a guard that leaves early, and an `else` that leaves becomes the first branch'),
			new Decision('controlFlow.trailingIfMinStatements', new Count(1, range: false), 'The statements the body of a trailing `if` has at least for it to become a guard', kind: DecisionKind::Parameter, default: 2),
		];
	}


	public function configure(Values $values): void
	{
		$this->minStatements = $values->get('controlFlow.trailingIfMinStatements')->getCount()[0];
	}


	public function getVisitedNodes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$list = $node->parent;
		if (
			!$node instanceof IfNode
			|| !$list instanceof PlainNodeList
			|| !($body = $node->body) instanceof BlockNode
			|| !$node->elseifs->isEmpty()
			|| $body->alwaysLeaves()
			|| NodeHelpers::hasHoistableDeclaration($list, $body)
		) {
			return;
		}

		$else = $node->else;
		if ($else === null) {
			$exit = self::findExit($list);
			$items = $list->getItems();
			if (
				$exit === null
				|| $items[count($items) - 1] !== $node
				|| count($body->statements) < $this->minStatements
			) {
				return;
			}

			if (self::report($node, $body, 'A trailing if must leave early instead of nesting the rest of the body', $context)) {
				self::invert($node, $body, $list, (new Builder)->statement($exit), $context);
			}

		} elseif (
			$else->body !== null
			&& ($else->body instanceof BlockNode || $else->body->interruptsFlow()) // an `else if` would stand in the guard without braces
			&& $else->body->alwaysLeaves()
		) {
			if (self::report($node, $body, 'The branch that leaves must come first, as a guard', $context)) {
				self::swap($node, $list);
			}
		}
	}


	/**
	 * Reports, and tells whether the fix may follow: not when a comment sits in the condition, around the opening
	 * brace or before the closing brace of the body, where it would end up inside the guard and say the opposite,
	 * nor on the closing brace or the `else` a swap removes.
	 */
	private static function report(IfNode $node, BlockNode $body, string $message, RuleContext $context): bool
	{
		$commented = $node->openParen->hasCommentUpTo($body->openBrace)
			|| $body->openBrace->hasTrailingComment()
			|| $body->closeBrace->hasLeadingComment()
			|| ($node->else && ($body->closeBrace->hasTrailingComment() || $node->else->elseKeyword->hasComment()));

		return $context->report($node, $message . ($commented ? ', but a comment stands in the way.' : '.'), fixable: !$commented);
	}


	/**
	 * The statement that leaves the body the list belongs to: `continue;` in a loop, `return;` in a function
	 * without a return type or with void and in a set hook; null elsewhere.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private static function findExit(PlainNodeList $list): ?string
	{
		$block = $list->parent;
		$owner = $block instanceof BlockNode ? $block->parent : null;
		if (
			$owner instanceof ForNode
			|| $owner instanceof ForeachNode
			|| $owner instanceof WhileNode
			|| $owner instanceof DoWhileNode
		) {
			return 'continue;';
		}

		if (!$owner instanceof FunctionLikeNode || ($owner instanceof PropertyHookNode && !$owner->name->equals('set'))) {
			return null; // a get hook returns a value
		}

		$type = $owner->returnType;
		return $type === null || ($type instanceof NamedTypeNode && strtolower($type->name->text) === 'void')
			? 'return;'
			: null;
	}


	/**
	 * `if (cond) { A }` at the end of the body becomes `if (!cond) { exit; }` followed by A.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private static function invert(
		IfNode $node,
		BlockNode $body,
		PlainNodeList $list,
		StatementNode $exit,
		RuleContext $context,
	): void
	{
		$style = $context->style;
		$indentation = $node->getFirstToken()->getLineIndentation();
		$exit->setEdgeTrivia([new Trivia(Trivia::Whitespace, $indentation . $style->indent)], [Trivia::fromText($style->lineEnding)]);

		$index = $list->indexOf($node);
		foreach ($body->statements->getItems() as $stmt) {
			$body->statements->removeItem($stmt);
			$list->insert(++$index, $stmt);
		}

		$body->statements->append($exit);
		$node->condition = NodeHelpers::negate($node->condition);
	}


	/**
	 * `if (cond) { A } else { B }` with B leaving becomes `if (!cond) { B }` followed by A.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private static function swap(IfNode $node, PlainNodeList $list): void
	{
		$else = $node->else;
		$old = $node->body;
		assert($else !== null && $old instanceof BlockNode && $else->body !== null);
		$leaving = $else->body;
		$else->body = null;
		$node->else = null;
		$node->body = $leaving;

		$index = $list->indexOf($node);
		foreach ($old->statements->getItems() as $stmt) {
			$old->statements->removeItem($stmt);
			$list->insert(++$index, $stmt);
		}

		$node->condition = NodeHelpers::negate($node->condition);
	}
}
