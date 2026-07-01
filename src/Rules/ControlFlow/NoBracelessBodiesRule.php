<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\{ElseifNode, ElseNode, StatementNode};
use PhpSyntax\Nodes\Statement\{BlockNode, DoWhileNode, EmptyStatementNode, ForeachNode, ForNode, IfNode, WhileNode};
use function count;


/**
 * The body of a control structure is always a block: `if ($a) x();` becomes `if ($a) {` … `}`.
 * An `else if` is left to `ElseifNotationRule`, an empty statement body stays, and so does a body that
 * ends by leaving PHP. The body moves a level deeper with its comments, the lines of a comment spanning
 * several among them.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true)]
final class NoBracelessBodiesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('braces.bracelessBody', Domain::state('forbidden'), 'The body of a control structure written without braces, `if ($a) foo();`')];
	}


	public function getVisitedNodes(): array
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
			|| !$context->report($body, 'The body of the `' . strtolower($node->getFirstToken()->text) . '` must be enclosed in braces.')
		) {
			return;
		}

		self::wrap($body, $context);
	}


	private static function wrap(StatementNode $body, RuleContext $context): void
	{
		$style = $context->style;
		$first = $body->getFirstToken();
		$last = $body->getLastToken();
		$indentation = ($first->getPrevious() ?? $first)->getLineIndentation();
		$ownLine = $first->startsLine();
		$leading = $first->leadingTrivia;

		$block = (new Builder)->fragment(BlockNode::class, '{}');
		$trailing = $last->trailingTrivia;
		$body->replaceWith($block);
		$block->statements->append($body);

		$before = $block->openBrace->getPrevious();
		if ($before && !$before->hasTrailingComment()) {
			$before->setTrailingTrivia([Trivia::fromText(' ')]);
			$block->openBrace->setLeadingTrivia([]);
		} elseif ($before?->trailingTrivia && $before->trailingTrivia[count($before->trailingTrivia) - 1]->isLineEnding()) {
			$block->openBrace->setLeadingTrivia([new Trivia(Trivia::Whitespace, $indentation)]);
		} else {
			$block->openBrace->setLeadingTrivia([]);
		}

		$block->openBrace->setTrailingTrivia([Trivia::fromText($style->lineEnding)]);
		$block->closeBrace->setLeadingTrivia([new Trivia(Trivia::Whitespace, $indentation)]);
		$block->closeBrace->setTrailingTrivia([Trivia::fromText($style->lineEnding)]);

		$start = 0;
		foreach ($leading as $i => $trivia) {
			if (!$trivia->isWhitespace()) {
				break;
			} elseif ($trivia->isLineEnding()) {
				$start = $i + 1;
			}
		}

		$first->setLeadingTrivia(array_slice($leading, $start));
		Indentation::set($first, $indentation . $style->indent, $indentation . $style->indent);
		if (!$ownLine) {
			for ($token = $first->getNext(); $token && $token !== $last->getNext(); $token = $token->getNext()) {
				if ($token->startsLine()) {
					$token->setIndentation($style->indent . $token->getIndentation());
				}
			}
		}

		$eol = Trivia::fromText($style->lineEnding);
		if ($trailing && $trailing[count($trailing) - 1]->isLineEnding()) {
			$last->setTrailingTrivia($trailing);
			$last->removeTrailingWhitespace();
		} else { // something follows on the line: it follows the closing brace now
			$last->setTrailingTrivia([$eol]);
			$block->closeBrace->setTrailingTrivia($trailing);
		}
	}
}
