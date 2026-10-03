<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\{ElseifNode, ElseNode, StatementNode};
use PhpSyntax\Nodes\Statement\{BlockNode, DoWhileNode, EmptyStatementNode, ForeachNode, ForNode, IfNode, WhileNode};
use function count;


/**
 * The body of a control structure is always a block: `if ($a) x();` becomes `if ($a) {` … `}`.
 * An `else if` is left to dresscode/elseifKeyword, an empty statement body stays, and so does a body that
 * ends by leaving PHP.
 */
#[RuleInfo(
	'dresscode/controlStructureBraces',
	Stage::Structure,
	description: 'Encloses the body of every control structure in braces',
)]
final class ControlStructureBracesRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [
			IfNode::class,
			ElseifNode::class,
			ElseNode::class,
			WhileNode::class,
			ForNode::class,
			ForeachNode::class,
			DoWhileNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$body = match (true) {
			$node instanceof IfNode,
			$node instanceof ElseifNode,
			$node instanceof ElseNode,
			$node instanceof WhileNode,
			$node instanceof ForNode,
			$node instanceof ForeachNode,
			$node instanceof DoWhileNode => $node->body,
			default => null,
		};
		if (
			$body === null
			|| $body instanceof BlockNode
			|| $body instanceof EmptyStatementNode
			|| ($node instanceof ElseNode && $body instanceof IfNode)
			// a body that ends by leaving PHP has no place for the closing brace: what follows the close
			// tag is markup, and a brace written there would be text
			|| $body->getLastToken()->is(Token::CloseTag)
			|| !$context->report($body, 'The body of a control structure must be enclosed in braces')
		) {
			return;
		}

		$this->wrap($body, $context);
	}


	private function wrap(StatementNode $body, RuleContext $context): void
	{
		$style = $context->style;
		$first = $body->getFirstToken();
		$last = $body->getLastToken();
		$indentation = ($first->getPrevious() ?? $first)->getLineIndentation();
		$ownLine = $first->startsLine();

		$block = (new Builder)->statement('{}');
		if (!$block instanceof BlockNode) {
			return;
		}

		$trailing = $last->trailingTrivia;
		$body->replaceWith($block);
		$block->statements->append($body);

		$before = $block->openBrace->getPrevious();
		$hasComment = false;
		foreach ($before ? $before->trailingTrivia : [] as $trivia) {
			$hasComment = $hasComment || $trivia->isComment();
		}

		if ($before && !$hasComment) {
			$before->setTrailingTrivia([new Trivia(Trivia::Whitespace, ' ')]);
			$block->openBrace->setLeadingTrivia([]);
		} else {
			$block->openBrace->setLeadingTrivia([new Trivia(Trivia::Whitespace, $indentation)]);
		}

		$block->openBrace->setTrailingTrivia([new Trivia(Trivia::LineEnding, $style->lineEnding)]);
		$block->closeBrace->setLeadingTrivia([new Trivia(Trivia::Whitespace, $indentation)]);
		$block->closeBrace->setTrailingTrivia([new Trivia(Trivia::LineEnding, $style->lineEnding)]);

		$first->setLeadingTrivia([new Trivia(Trivia::Whitespace, $indentation . $style->indent)]);
		if (!$ownLine) {
			for ($token = $first->getNext(); $token && $token !== $last->getNext(); $token = $token->getNext()) {
				if ($token->startsLine()) {
					$token->setIndentation($style->indent . $token->getIndentation());
				}
			}
		}

		$eol = new Trivia(Trivia::LineEnding, $style->lineEnding);
		if ($trailing && $trailing[count($trailing) - 1]->isLineEnding()) {
			$last->setTrailingTrivia($trailing);
			$last->removeTrailingWhitespace();
		} else { // something follows on the line: it follows the closing brace now
			$last->setTrailingTrivia([$eol]);
			$block->closeBrace->setTrailingTrivia($trailing);
		}
	}
}
