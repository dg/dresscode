<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * The short ternary `$a ?: $b`: required, it is written for a one-line ternary that repeats its condition, forbidden,
 * the ternary is written in full, `$a ? $a : $b`, and in parentheses where it is a link of a chain. Either way only for
 * a condition free of side effects, which a second evaluation cannot change; another one is only reported.
 */
#[RuleInfo(Stage::Structure)]
final class ShortTernaryRule extends NodeRule
{
	private const Path = 'expressions.ternary.short';

	private bool $required = true;


	public static function getDecisions(): array
	{
		return [new Decision(
			self::Path,
			Domain::state('required', 'forbidden'),
			'`$a ?: $b`, written for `$a ? $a : $b` or the other way round where the condition can be read twice',
		)];
	}


	public function configure(Values $values): void
	{
		$this->required = $values->find(self::Path)?->getWord() !== 'forbidden';
	}


	public function getVisitedNodes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TernaryNode) {
			return;
		} elseif ($this->required) {
			$this->shorten($node, $context);
		} elseif ($node->then === null) {
			$this->expand($node, $context);
		}
	}


	private function shorten(TernaryNode $node, RuleContext $context): void
	{
		if (
			$node->then === null
			|| !$node->condition->isRepeatableRead()
			|| !$node->condition->matches($node->then)
			|| $node->question->getCurrentLine() !== $node->colon->getCurrentLine()
			|| $node->question->hasCommentUpTo($node->colon)
			|| !$context->report($node, 'The ternary repeating its condition must be written `?:`.')
		) {
			return;
		}

		$node->then = null;
		$node->question->setTrailingTrivia([]);
	}


	private function expand(TernaryNode $node, RuleContext $context): void
	{
		$fixable = $node->condition->isRepeatableRead() && !$node->question->hasCommentUpTo($node->colon);
		if (!$context->report($node, 'The ternary must be written in full, not `?:`.', fixable: $fixable) || !$fixable) {
			return;
		}

		$then = $node->condition->withoutEdgeTrivia();
		$then->getLastToken()->setTrailingTrivia([Trivia::fromText(' ')]);
		$node->question->setTrailingTrivia([Trivia::fromText(' ')]);
		$node->then = $then;
		// PHP refuses a full ternary in a chain without parentheses
		if ($node->parent instanceof TernaryNode) {
			$node->replaceWith((new Builder)->parenthesize($node));
		}
	}
}
