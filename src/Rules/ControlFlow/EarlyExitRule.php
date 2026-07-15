<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token, Trivia, TriviaKind};
use PhpSyntax\Nodes\{ClassLikeNode, FileNode, NodeList, StatementNode};
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{BlockNode, DoWhileNode, ForeachNode, ForNode, FunctionNode, IfNode, NamespaceNode, WhileNode};
use PhpSyntax\Nodes\Type\NamedTypeNode;
use function count;


/**
 * Leave early instead of nesting: an `if` that is the last statement of a function or loop body and does
 * not leave itself turns into a guard, `if (!cond) { return; }` or `continue;`, with its statements following
 * it; an `if` whose `else` leaves while the `if` branch does not swaps the two, so that the leaving branch
 * comes first and the rest needs no else. A trailing `if` in a function with a return type other than void stays,
 * because a bare return would not do there; a comment inside the condition or at the end of the body is only
 * reported. At the top of a file, a body declaring a function or a class keeps its shape too, because out of
 * the if PHP would bind the declaration before the code runs; in a function or a loop it binds it where it
 * stands. The moved statements keep their indentation, which is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/early-exit',
	Stage::Structure,
	description: 'Turns a trailing if into a guard that leaves early, and an else that leaves into the first branch',
)]
final class EarlyExitRule extends NodeRule implements ConfigurableRule
{
	private int $minStatements = 2;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minStatements' => Expect::int(2)->min(1)->description('A trailing if is turned into a guard only when its body has at least this many statements'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minStatements = $options['minStatements'];
	}


	public function getVisitedTypes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$list = $node->parent;
		if (
			!$node instanceof IfNode
			|| !$list instanceof NodeList
			|| !($body = $node->body) instanceof BlockNode
			|| !$node->elseifs->isEmpty()
			|| NodeHelpers::endsWithExit($body)
			|| (($list->parent instanceof FileNode || $list->parent instanceof NamespaceNode) && self::declares($body))
		) {
			return;
		}

		$else = $node->else;
		if ($else === null) {
			$exit = self::exitOf($list);
			$items = $list->getItems();
			if (
				$exit === null
				|| $items[count($items) - 1] !== $node
				|| count($body->statements) < $this->minStatements
			) {
				return;
			}

			if ($this->report($node, $body, 'A trailing if must leave early instead of nesting the rest of the body', $context)) {
				$this->invert($node, $body, $list, (new Parser)->parseStatement($exit), $context);
			}

		} elseif ($else->body instanceof BlockNode && NodeHelpers::endsWithExit($else->body)) {
			if ($this->report($node, $body, 'The branch that leaves must come first, as a guard', $context)) {
				$this->swap($node, $list);
			}
		}
	}


	/**
	 * Reports, and tells whether the fix may follow: not when a comment sits in the condition or before the
	 * closing brace of the body, where it would end up inside the guard.
	 */
	private function report(IfNode $node, BlockNode $body, string $message, RuleContext $context): bool
	{
		$commented = $node->openParen->hasCommentUpTo($node->closeParen);
		foreach ($body->closeBrace->leadingTrivia as $trivia) {
			$commented = $commented || $trivia->isComment();
		}

		return $context->report($node, $message, fixable: !$commented);
	}


	/** Whether the block declares a function or a class, which at the top of a file PHP binds before the code runs. */
	private static function declares(BlockNode $block): bool
	{
		return array_any($block->statements->getItems(), fn(StatementNode $stmt) => $stmt instanceof FunctionNode || $stmt instanceof ClassLikeNode);
	}


	/**
	 * The statement that leaves the body the list belongs to: `continue;` in a loop, `return;` in a function
	 * without a return type or with void; null elsewhere.
	 * @param NodeList<Node> $list
	 */
	private static function exitOf(NodeList $list): ?string
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

		if (!$owner instanceof FunctionNode && !$owner instanceof MethodNode && !$owner instanceof ClosureNode) {
			return null;
		}

		$type = $owner->returnType;
		return $type === null || ($type instanceof NamedTypeNode && strtolower($type->name->text) === 'void')
			? 'return;'
			: null;
	}


	/**
	 * `if (cond) { A }` at the end of the body becomes `if (!cond) { exit; }` followed by A.
	 * @param NodeList<Node> $list
	 */
	private function invert(
		IfNode $node,
		BlockNode $body,
		NodeList $list,
		StatementNode $exit,
		RuleContext $context,
	): void
	{
		$style = $context->getStyle();
		$indentation = $node->getFirstToken()?->getLineIndentation() ?? '';
		$exit->setEdgeTrivia([new Trivia(TriviaKind::Whitespace, $indentation . $style->indent)], [new Trivia(TriviaKind::EndOfLine, $style->eol)]);

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
	 * @param NodeList<Node> $list
	 */
	private function swap(IfNode $node, NodeList $list): void
	{
		$else = $node->else;
		$old = $node->body;
		assert($else !== null && $old instanceof BlockNode && $else->body instanceof BlockNode);
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
