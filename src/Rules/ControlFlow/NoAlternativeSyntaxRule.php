<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\{ElseifNode, ElseNode, PlainNodeList, StatementNode};
use PhpSyntax\Nodes\Statement\{BlockNode, DeclareNode, EmptyStatementNode, ForeachNode, ForNode, IfNode, SwitchNode, WhileNode};


/**
 * Braces instead of the alternative syntax: `if (...) {` … `}`, not `if (...):` … `endif;`.
 */
#[RuleInfo(Stage::Structure)]
final class NoAlternativeSyntaxRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('braces.alternativeSyntax', Domain::state('forbidden'), '`if: … endif;` is written with braces')];
	}


	public function getVisitedNodes(): array
	{
		return [IfNode::class, WhileNode::class, ForNode::class, ForeachNode::class, SwitchNode::class, DeclareNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$colon, $semicolon] = match (true) {
			$node instanceof IfNode,
			$node instanceof WhileNode,
			$node instanceof ForNode,
			$node instanceof ForeachNode,
			$node instanceof SwitchNode,
			$node instanceof DeclareNode => [$node->colon, $node->semicolon],
			default => [null, null],
		};
		// a close tag closes the statement instead of a semicolon and the braces cannot swallow it
		$closeTag = $semicolon?->is(Token::CloseTag) ? $semicolon : null;
		$fixable = $closeTag === null || $node->parent instanceof PlainNodeList;
		if (
			$colon === null
			|| !$context->report($colon, 'The `' . strtolower((string) $node->getFirstToken()?->text) . '` must be written with braces instead of the alternative syntax.', fixable: $fixable)
			|| !$fixable
		) {
			return;
		}

		if ($node instanceof SwitchNode) {
			self::rewriteSwitch($node, $closeTag);

		} elseif ($node instanceof IfNode) {
			self::rewriteIf($node, $closeTag);

		} elseif (
			$node instanceof WhileNode
			|| $node instanceof ForNode
			|| $node instanceof ForeachNode
			|| $node instanceof DeclareNode
		) {
			$end = $node->endKeyword;
			if ($end === null || $node->statements === null) {
				return;
			}

			$block = self::buildBlock($colon, $node->statements, $end->leadingTrivia, ($closeTag === null ? $semicolon ?? $end : $end)->trailingTrivia);
			$node->colon = null;
			$node->statements = null;
			$node->endKeyword = null;
			$node->semicolon = null;
			$node->body = $block;
			self::keepCloseTag($node, $closeTag);
		}
	}


	private static function rewriteIf(IfNode $node, ?Token $closeTag): void
	{
		$end = $node->endKeyword;
		if ($end === null) {
			return;
		}

		$last = $closeTag === null ? $node->semicolon ?? $end : $end;

		$branches = [$node, ...$node->elseifs->getItems(), ...($node->else !== null ? [$node->else] : [])];
		foreach ($branches as $i => $branch) {
			if ($branch->colon === null || $branch->statements === null) {
				continue;
			}

			$next = $branches[$i + 1] ?? null;
			$nextKeyword = match (true) {
				$next instanceof ElseifNode => $next->elseifKeyword,
				$next instanceof ElseNode => $next->elseKeyword,
				default => null,
			};
			[$closeLeading, $closeTrailing] = $nextKeyword !== null
				? [$nextKeyword->leadingTrivia, [Trivia::fromText(' ')]]
				: [$end->leadingTrivia, $last->trailingTrivia];
			$block = self::buildBlock($branch->colon, $branch->statements, $closeLeading, $closeTrailing);
			if ($nextKeyword !== null) {
				$nextKeyword->setLeadingTrivia([]);
			}

			$branch->colon = null;
			$branch->statements = null;
			$branch->body = $block;
		}

		$node->endKeyword = null;
		$node->semicolon = null;
		self::keepCloseTag($node, $closeTag);
	}


	private static function rewriteSwitch(SwitchNode $node, ?Token $closeTag): void
	{
		$end = $node->endKeyword;
		$colon = $node->colon;
		if ($end === null || $colon === null) {
			return;
		}

		$last = $closeTag === null ? $node->semicolon ?? $end : $end;
		self::ensureSpaceBefore($colon);
		$openBrace = Token::fromText('{')
			->setLeadingTrivia($colon->leadingTrivia)
			->setTrailingTrivia($colon->trailingTrivia);
		$closeBrace = Token::fromText('}')
			->setLeadingTrivia($end->leadingTrivia)
			->setTrailingTrivia($last->trailingTrivia);
		$node->colon = null;
		$node->endKeyword = null;
		$node->semicolon = null;
		$node->openBrace = $openBrace;
		$node->closeBrace = $closeBrace;
		self::keepCloseTag($node, $closeTag);
	}


	/**
	 * The close tag that ended the alternative syntax lives on as a statement of its own,
	 * the way the parser reads it after a block.
	 */
	private static function keepCloseTag(StatementNode $node, ?Token $closeTag): void
	{
		if ($closeTag === null || !($list = $node->parent) instanceof PlainNodeList) {
			return;
		}

		$statement = (new Builder)->fragment(EmptyStatementNode::class, '?' . '>');
		$statement->semicolon
			->setText($closeTag->text)
			->setLeadingTrivia($closeTag->leadingTrivia)
			->setTrailingTrivia($closeTag->trailingTrivia);
		$list->insertAfter($node, $statement);
	}


	/**
	 * @param PlainNodeList<StatementNode> $stmts
	 * @param list<Trivia> $closeLeading
	 * @param list<Trivia> $closeTrailing
	 */
	private static function buildBlock(Token $colon, PlainNodeList $stmts, array $closeLeading, array $closeTrailing): BlockNode
	{
		self::ensureSpaceBefore($colon);
		$block = (new Builder)->fragment(BlockNode::class, '{}');
		$block->openBrace
			->setLeadingTrivia($colon->leadingTrivia)
			->setTrailingTrivia($colon->trailingTrivia);
		$block->closeBrace
			->setLeadingTrivia($closeLeading)
			->setTrailingTrivia($closeTrailing);
		foreach ($stmts->getItems() as $stmt) {
			$stmts->removeItem($stmt);
			$block->statements->append($stmt);
		}

		return $block;
	}


	/**
	 * The colon sits right after the closing parenthesis; the brace replacing it wants a space before.
	 */
	private static function ensureSpaceBefore(Token $colon): void
	{
		$previous = $colon->getPrevious();
		if ($previous?->getTrailingSpace() === '' && $colon->leadingTrivia === []) {
			$previous->setTrailingSpace(' ');
		}
	}
}
