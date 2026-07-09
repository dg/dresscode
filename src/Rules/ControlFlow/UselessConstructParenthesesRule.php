<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia, TriviaKind};
use PhpSyntax\Nodes\{CaseNode, OperatorNode, SeparatedNodeList};
use PhpSyntax\Nodes\Expression\{CloneNode, IncludeNode, ParenthesizedNode, PrintNode, YieldNode};
use PhpSyntax\Nodes\Statement\{BreakNode, ContinueNode, EchoNode, ReturnNode};


/**
 * No parentheses around the whole operand of `return`, `echo`, `print`, `clone`, `yield`, `break`,
 * `continue`, `include` and the value of a case.
 */
#[RuleInfo(
	'dresscode/useless-construct-parentheses',
	Stage::Structure,
	description: 'Removes parentheses around the operand of a language construct',
	group: Group::Cleanup,
)]
final class UselessConstructParenthesesRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ParenthesizedNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ParenthesizedNode) {
			return;
		}

		$parent = $node->parent;
		$unneeded = match (true) {
			$parent instanceof ReturnNode, $parent instanceof PrintNode, $parent instanceof CloneNode,
			$parent instanceof BreakNode, $parent instanceof ContinueNode,
			$parent instanceof IncludeNode, $parent instanceof CaseNode => true,
			// the operand of a yield is `key => value`, so only the parentheses around the value are its own
			$parent instanceof YieldNode => $parent->value === $node,
			$parent instanceof SeparatedNodeList => $parent->parent instanceof EchoNode,
			default => false,
		};
		// every layer of a chain of parentheses goes at once: one layer per pass would need as many passes
		// as the source has layers, and a file written to nest a thousand of them would never converge
		$expression = $node->expression;
		while ($expression instanceof ParenthesizedNode && !self::holdsComment($expression)) {
			$expression = $expression->expression;
		}

		if (
			!$unneeded
			|| self::holdsComment($node)
			// print, clone, yield and include have a precedence, and what binds no tighter would come apart
			|| (
				$parent instanceof OperatorNode
				&& $expression instanceof OperatorNode
				&& $expression->getPrecedence()[0] <= $parent->getPrecedence()[0]
			)
			|| !$context->report($node, 'Useless parentheses around the operand')
		) {
			return;
		}

		$inner = clone $expression;
		$first = $inner->getFirstToken();
		if ($first !== null && $first->getTrailingSpace() !== null) {
			$first->setLeadingTrivia([]);
		}

		$inner->getLastToken()?->removeTrailingWhitespace();
		$node->replaceWith($inner);
		$previous = $inner->getFirstToken()?->getPrevious();
		if ($previous?->getTrailingSpace() === '') {
			$previous->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		}
	}


	/** Whether a comment stands between the parentheses and what they hold, which dropping them would lose. */
	private static function holdsComment(ParenthesizedNode $node): bool
	{
		$first = $node->expression->getFirstToken();
		$last = $node->expression->getLastToken();
		return $node->openParen->hasComment()
			|| $node->closeParen->hasComment()
			|| ($first !== null && self::hasComment($first->leadingTrivia))
			|| ($last !== null && self::hasComment($last->trailingTrivia));
	}


	/** @param list<Trivia> $trivia */
	private static function hasComment(array $trivia): bool
	{
		return array_any($trivia, fn(Trivia $item) => $item->isComment());
	}
}
