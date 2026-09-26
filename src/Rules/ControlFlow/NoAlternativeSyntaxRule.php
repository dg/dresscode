<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\{ElseIfNode, ElseNode, NodeList, StatementNode};
use PhpSyntax\Nodes\Statement\{BlockNode, DeclareNode, EmptyStatementNode, ForeachNode, ForNode, IfNode, SwitchNode, WhileNode};
use function ord;


/**
 * Braces instead of the alternative syntax: `if (...) {` … `}`, not `if (...):` … `endif;`.
 */
#[RuleInfo(
	'dresscode/no-alternative-syntax',
	Stage::Structure,
	description: 'Replaces the alternative syntax with braces',
)]
final class NoAlternativeSyntaxRule extends NodeRule
{
	public function getVisitedTypes(): array
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
		$closeTag = $semicolon?->is(TokenKind::CloseTag) ? $semicolon : null;
		if (
			$colon === null
			|| ($closeTag !== null && !$node->parent instanceof NodeList)
			|| !$context->report($colon, 'The alternative syntax must be written with braces')
		) {
			return;
		}

		if ($node instanceof SwitchNode) {
			$this->rewriteSwitch($node, $closeTag);

		} elseif ($node instanceof IfNode) {
			$this->rewriteIf($node, $closeTag);

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

			$block = $this->buildBlock($colon, $node->statements, $end->leadingTrivia, ($closeTag === null ? $semicolon ?? $end : $end)->trailingTrivia);
			$node->colon = null;
			$node->statements = null;
			$node->endKeyword = null;
			$node->semicolon = null;
			$node->body = $block;
			self::keepCloseTag($node, $closeTag);
		}
	}


	private function rewriteIf(IfNode $node, ?Token $closeTag): void
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
				$next instanceof ElseIfNode => $next->elseifKeyword,
				$next instanceof ElseNode => $next->elseKeyword,
				default => null,
			};
			[$closeLeading, $closeTrailing] = $nextKeyword !== null
				? [$nextKeyword->leadingTrivia, [new Trivia(TriviaKind::Whitespace, ' ')]]
				: [$end->leadingTrivia, $last->trailingTrivia];
			$block = $this->buildBlock($branch->colon, $branch->statements, $closeLeading, $closeTrailing);
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


	private function rewriteSwitch(SwitchNode $node, ?Token $closeTag): void
	{
		$end = $node->endKeyword;
		$colon = $node->colon;
		if ($end === null || $colon === null) {
			return;
		}

		$last = $closeTag === null ? $node->semicolon ?? $end : $end;
		$openBrace = (new Token(ord('{'), '{'))
			->setLeadingTrivia(self::braceLeading($colon))
			->setTrailingTrivia($colon->trailingTrivia);
		$closeBrace = (new Token(ord('}'), '}'))
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
		if ($closeTag === null || !($list = $node->parent) instanceof NodeList) {
			return;
		}

		$statement = (new Parser)->parseStatement('?' . '>');
		assert($statement instanceof EmptyStatementNode);
		$statement->semicolon
			->setText($closeTag->text)
			->setLeadingTrivia($closeTag->leadingTrivia)
			->setTrailingTrivia($closeTag->trailingTrivia);
		$list->insert($list->indexOf($node) + 1, $statement);
	}


	/**
	 * @param NodeList<StatementNode> $stmts
	 * @param list<Trivia> $closeLeading
	 * @param list<Trivia> $closeTrailing
	 */
	private function buildBlock(Token $colon, NodeList $stmts, array $closeLeading, array $closeTrailing): BlockNode
	{
		$block = (new Parser)->parseStatement('{}');
		assert($block instanceof BlockNode);
		$block->openBrace
			->setLeadingTrivia(self::braceLeading($colon))
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
	 * @return list<Trivia>
	 */
	private static function braceLeading(Token $colon): array
	{
		return $colon->getPrevious()?->getTrailingSpace() === '' && $colon->leadingTrivia === []
			? [new Trivia(TriviaKind::Whitespace, ' ')]
			: $colon->leadingTrivia;
	}
}
