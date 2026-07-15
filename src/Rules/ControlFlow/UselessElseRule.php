<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token, Trivia, TriviaKind};
use PhpSyntax\Nodes\{ClassLikeNode, ElseIfNode, FileNode, NodeList};
use PhpSyntax\Nodes\Statement\{BlockNode, FunctionNode, IfNode, NamespaceNode};


/**
 * An `else` after branches that all end by leaving (`return`, `throw`, `break`, `continue`, `exit`, `goto`)
 * is dropped and its statements follow the `if`; an empty `else` is dropped too. At the top of a file, an
 * `else` declaring a function or a class stays, because out of the `else` PHP would bind the declaration
 * before the code runs; in a function or a loop it binds it where it stands. When asked, an `elseif` after such an `if` becomes an `if` of its own,
 * with the later branches; a chain of `elseif` that reads as one is a matter of taste, so it stays by default.
 */
#[RuleInfo(
	'dresscode/useless-else',
	Stage::Structure,
	description: 'Removes an else after branches which always leave, and may turn an elseif there into an if',
	group: Group::Cleanup,
)]
final class UselessElseRule extends NodeRule implements ConfigurableRule
{
	private bool $elseif = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'elseif' => Expect::bool(false)->description('An elseif after an if that always leaves becomes an if of its own'),
		]);
	}


	public function configure(array $options): void
	{
		$this->elseif = $options['elseif'];
	}


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
		) {
			return;
		}

		$elseif = $node->elseifs->getItems()[0] ?? null;
		if ($elseif !== null && $this->elseif && NodeHelpers::endsWithExit($node->body)) {
			if (
				$elseif->body instanceof BlockNode
				&& $context->report($elseif, 'Useless elseif, the branches before it always leave')
			) {
				$this->splitElseif($node, $elseif, $list, $context);
			}

			return;
		}

		if (!($else = $node->else) || !($body = $else->body) instanceof BlockNode) {
			return;
		}

		$stmts = $body->statements->getItems();
		$empty = $stmts === [] && !$body->openBrace->hasCommentUpTo($body->closeBrace);
		$branches = [$node->body];
		foreach ($node->elseifs->getItems() as $elseif) {
			$branches[] = $elseif->body;
		}

		foreach ($branches as $branch) {
			if (!$empty && (!$branch instanceof BlockNode || !NodeHelpers::endsWithExit($branch))) {
				return;
			}
		}

		$top = $list->parent instanceof FileNode || $list->parent instanceof NamespaceNode;
		foreach ($stmts as $stmt) {
			if ($top && ($stmt instanceof FunctionNode || $stmt instanceof ClassLikeNode)) {
				return;
			}
		}

		if (!$context->report($else, $empty ? 'Empty else' : 'Useless else, the branches before it always leave')) {
			return;
		}

		$closing = $body->closeBrace->trailingTrivia;
		$index = $list->indexOf($node);
		foreach ($stmts as $stmt) {
			$body->statements->removeItem($stmt);
			$list->insert(++$index, $stmt);
		}

		$node->else = null;
		$node->setEdgeTrivia(trailing: $closing);
	}


	/**
	 * The elseif becomes an if statement of its own after the if, taking the later branches with it.
	 * @param NodeList<Node> $list
	 */
	private function splitElseif(IfNode $node, ElseIfNode $elseif, NodeList $list, RuleContext $context): void
	{
		$new = (new Parser)->parseStatement('if (0) {}');
		assert($new instanceof IfNode && $elseif->body !== null);
		$cond = $elseif->condition->withoutEdgeTrivia();
		$body = $elseif->body;
		$elseif->body = null;
		$new->condition = $cond;
		$new->body = $body;
		$node->elseifs->removeItem($elseif);
		foreach ($node->elseifs->getItems() as $later) {
			$node->elseifs->removeItem($later);
			$new->elseifs->append($later);
		}

		$else = $node->else;
		$node->else = null;
		$new->else = $else;

		$style = $context->getStyle();
		$indentation = $node->getFirstToken()?->getLineIndentation() ?? '';
		$node->setEdgeTrivia(trailing: [new Trivia(TriviaKind::EndOfLine, $style->eol)]);
		$new->setEdgeTrivia(leading: $indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)]);
		$list->insert($list->indexOf($node) + 1, $new);
	}
}
