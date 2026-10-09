<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\{ElseifNode, PlainNodeList};
use PhpSyntax\Nodes\Statement\{BlockNode, IfNode};


/**
 * An `else` after branches that all end by leaving (`return`, `throw`, `break`, `continue`, `exit`, `goto`) is dropped
 * and its statements follow the `if`; an empty `else` is dropped too. At the top of a file, an `else` declaring a
 * function or a class stays, because out of the `else` PHP would bind the declaration before the code runs; in a
 * function or a loop it binds it where it stands. With `controlFlow.afterExit.elseif`, an `elseif` after such an `if`
 * becomes an `if` of its own, with the later branches; a chain of `elseif` that reads as one is a matter of taste, so
 * it stays by default.
 */
#[RuleInfo(Stage::Structure)]
final class UselessElseRule extends NodeRule
{
	private const ElseAfterExit = 'controlFlow.afterExit.else';
	private const ElseifAfterExit = 'controlFlow.afterExit.elseif';

	private bool $else = true;

	private bool $elseif = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::ElseAfterExit, Domain::state(), 'An `else` after branches that always leave, by `return`, `throw`, `break`, `continue`, `exit` or `goto`, whose statements then follow the `if`, and an empty `else`'),
			new Decision(self::ElseifAfterExit, Domain::state(), 'An `elseif` after an `if` that always leaves, which becomes an `if` of its own with the later branches'),
		];
	}


	public function configure(Values $values): void
	{
		$this->else = !$values->isKept(self::ElseAfterExit);
		$this->elseif = !$values->isKept(self::ElseifAfterExit);
	}


	public function getVisitedNodes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			|| !($list = $node->parent) instanceof PlainNodeList
			|| $node->body === null
		) {
			return;
		}

		$elseif = $node->elseifs->getItems()[0] ?? null;
		if ($elseif !== null && $this->elseif && $node->body->alwaysLeaves()) {
			$condition = $elseif->condition;
			if (
				($body = $elseif->body) instanceof BlockNode
				// the new `if` is written afresh around its condition, without the comments there
				&& !$node->body->getLastToken()->hasCommentUpTo($condition->getFirstToken())
				&& !$condition->getLastToken()->hasCommentUpTo($body->openBrace)
				&& $context->report($elseif, 'Useless elseif, because the branches before it always leave.', decision: self::ElseifAfterExit)
			) {
				// the `else` goes with the later branches into the new `if`, which the next pass visits
				$this->splitElseif($node, $elseif, $list, $context);
				return;
			}
		}

		if (
			!$this->else
			|| !($else = $node->else)
			|| !($body = $else->body) instanceof BlockNode
			|| $else->elseKeyword->hasComment()
			|| $body->openBrace->getNext()?->is(Token::CloseTag)
			|| $body->closeBrace->getNext()?->is(Token::CloseTag)
			|| $body->closeBrace->getPrevious()?->is([Token::CloseTag, Token::InlineHtml])
		) {
			return;
		}

		$stmts = $body->statements->getItems();
		$empty = $stmts === [] && !$body->openBrace->hasCommentUpTo($body->closeBrace);
		$branches = [$node->body];
		foreach ($node->elseifs->getItems() as $elseif) {
			$branches[] = $elseif->body;
		}

		foreach ($branches as $branch) {
			if (!$empty && ($branch === null || !$branch->alwaysLeaves())) {
				return;
			}
		}

		if (NodeHelpers::hasHoistableDeclaration($list, $body)) {
			return;
		}

		if (!$context->report($else, $empty ? 'Useless else, because it is empty.' : 'Useless else, because the branches before it always leave.', decision: self::ElseAfterExit)) {
			return;
		}

		$node->else = null;
		$else->body = null;
		$list->insertAfter($node, $body);
		$body->openBrace->ensureStartsLine($context->style->lineEnding);
		$body->openBrace->setIndentation($node->getFirstToken()->getLineIndentation());
		$body->unwrap();
	}


	/**
	 * The `elseif` becomes an `if` statement of its own after the `if`, taking the later branches with it.
	 * @param PlainNodeList<Node> $list
	 */
	private function splitElseif(IfNode $node, ElseifNode $elseif, PlainNodeList $list, RuleContext $context): void
	{
		$new = (new Builder)->fragment(IfNode::class, 'if ($condition) {}', condition: $elseif->condition);
		$body = $elseif->body;
		assert($body !== null);
		$elseif->body = null;
		$new->body = $body;
		$node->elseifs->removeItem($elseif);
		foreach ($node->elseifs->getItems() as $later) {
			$node->elseifs->removeItem($later);
			$new->elseifs->append($later);
		}

		$else = $node->else;
		$node->else = null;
		$new->else = $else;

		$style = $context->style;
		$indentation = $node->getFirstToken()->getLineIndentation();
		$node->setEdgeTrivia(trailing: [Trivia::fromText($style->lineEnding)]);
		$new->setEdgeTrivia(leading: $indentation === '' ? [] : [new Trivia(Trivia::Whitespace, $indentation)]);
		$list->insertAfter($node, $new);
	}
}
