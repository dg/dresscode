<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\Expression\{AssignmentNode, TernaryNode, UnaryOpNode};
use PhpSyntax\Nodes\{ExpressionNode, FunctionLikeNode};
use PhpSyntax\Nodes\Statement\{BlockNode, ExpressionStatementNode, IfNode, ReturnNode};
use function count;


/**
 * An `if` and an `else` that each only return a value, or each only assign the same variable, are one
 * ternary: `return $c ? $a : $b;`, `$x = $c ? $a : $b;`. A ternary spanning lines has `?` and `:` at the start
 * of theirs, a negated condition then losing its negation for the branches swapped, and a comment of a branch, one
 * at most, follows its value; any other comment keeps the statement, which is only reported, and so does nothing
 * where a branch is a ternary itself, which reads better in the if. A function returning by reference keeps its
 * returns, because a ternary is no variable to return a reference to. The fix is risky where the condition runs code
 * and the variable reads before it an offset that is more than a variable, a literal or a constant, or a class given
 * by an expression.
 */
#[RuleInfo(Stage::Structure)]
final class TernaryForIfRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'controlFlow.ifElseReturning',
			Domain::state('forbidden'),
			'`if ($c) { return 1; } else { return 2; }` is `return $c ? 1 : 2;`',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			|| !$node->elseifs->isEmpty()
			|| !$node->body instanceof BlockNode
			|| !($else = $node->else) || !$else->body instanceof BlockNode
			|| ($then = self::findSingleStatement($node->body)) === null
			|| ($otherwise = self::findSingleStatement($else->body)) === null
		) {
			return;
		}

		if ($then instanceof ReturnNode && $otherwise instanceof ReturnNode) {
			if ($then->expression === null || $otherwise->expression === null || self::returnsReference($node)) {
				return;
			}

			[$target, $a, $b] = [null, $then->expression, $otherwise->expression];

		} elseif (
			$then instanceof ExpressionStatementNode && ($x = $then->expression) instanceof AssignmentNode
			&& $otherwise instanceof ExpressionStatementNode && ($y = $otherwise->expression) instanceof AssignmentNode
			&& ($assigned = $x->target) instanceof ExpressionNode
			&& $assigned->isRepeatableRead() && $assigned->matches($y->target)
		) {
			[$target, $a, $b] = [$assigned, $x->expression, $y->expression];

		} else {
			return;
		}

		if ($a instanceof TernaryNode || $b instanceof TernaryNode) { // a ternary in a branch reads better in the if
			return;
		}

		$comments = [self::findBranchComments($then), self::findBranchComments($otherwise)];
		$fixable = count($comments[0]) <= 1
			&& count($comments[1]) <= 1
			&& array_all(
				[...$comments[0], ...$comments[1]],
				fn(Trivia $comment) => $comment->is(Trivia::Comment) && !str_contains($comment->text, "\n"),
			)
			&& count($node->getInnerComments()) === count($comments[0]) + count($comments[1])
				+ count($node->condition->getInnerComments()) + count($a->getInnerComments()) + count($b->getInnerComments());
		$reordered = $target !== null && $target->hasEarlyReads() && $node->condition->hasEffect();
		if (!$context->report(
			$node->ifKeyword,
			'The if-else picking one of two values must be written as a ternary.',
			fixable: $fixable,
			risk: $reordered ? Risk::BehaviorChanges : null,
			because: $reordered ? 'the ternary evaluates the target before the condition, which may change it' : null,
		)) {
			return;
		}

		$condition = $node->condition;
		$multiline = $comments !== [[], []] || $condition->isMultiLine() || $a->isMultiLine() || $b->isMultiLine();
		if ($multiline && $condition instanceof UnaryOpNode && $condition->operator->is('!')) {
			[$condition, $a, $b, $comments] = [NodeHelpers::negate($condition), $b, $a, array_reverse($comments)];
		}

		$builder = new Builder;
		$ternary = $builder->ternary($condition, $a, $b);
		$statement = $target === null
			? $builder->statement('return $value;', value: $ternary)
			: $builder->statement('$target = $value;', target: $target, value: $ternary);
		$node->replaceWith($statement);
		if ($multiline) { // `?` and `:` open the lines, a comment of a branch follows its value
			$eol = $context->style->lineEnding;
			foreach ([[$ternary->then, $comments[0]], [$statement, $comments[1]]] as [$owner, $comment]) {
				if ($comment !== [] && ($last = $owner?->getLastToken())) {
					$last->setTrailingTrivia([
						Trivia::fromText(' '),
						new Trivia($comment[0]->id, $comment[0]->text),
						...$last->trailingTrivia,
					]);
				}
			}

			$indentation = $statement->getFirstToken()->getLineIndentation() . $context->style->indent;
			foreach ([$ternary->question, $ternary->colon] as $operator) {
				$operator->ensureStartsLine($eol);
				$operator->setIndentation($indentation);
			}
		}

		$context->markRewritten($statement);
	}


	/**
	 * The comments of a branch that do not travel with its value: on the lines above its statement and after it.
	 * @return list<Trivia>
	 */
	private static function findBranchComments(Node $statement): array
	{
		$trivia = [...$statement->getFirstToken()->leadingTrivia ?? [], ...$statement->getLastToken()->trailingTrivia ?? []];
		return array_values(array_filter($trivia, fn(Trivia $trivia) => $trivia->isComment()));
	}


	private static function findSingleStatement(BlockNode $block): ?Node
	{
		$stmts = $block->statements->getItems();
		return count($stmts) === 1 ? $stmts[0] : null;
	}


	/** Whether the returns leave a function that returns by reference. */
	private static function returnsReference(Node $node): bool
	{
		return $node->findAncestor(FunctionLikeNode::class)?->ampersand !== null;
	}
}
