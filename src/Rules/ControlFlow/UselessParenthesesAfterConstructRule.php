<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{CaseNode, OperatorNode, SeparatedNodeList};
use PhpSyntax\Nodes\Expression\{CloneNode, IncludeNode, ParenthesizedNode, PrintNode, YieldNode};
use PhpSyntax\Nodes\Statement\{BreakNode, ContinueNode, EchoNode, ReturnNode};


/**
 * No parentheses around the whole operand of `return`, `echo`, `print`, `clone`, `yield`, `break`,
 * `continue`, `include` and the value of a case.
 */
#[RuleInfo(Stage::Structure)]
final class UselessParenthesesAfterConstructRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.parenthesesAfterConstruct', Domain::state('forbidden'), '`return ($x);` is `return $x;`')];
	}


	public function getVisitedNodes(): array
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
		$keyword = strtolower((string) ($parent instanceof SeparatedNodeList ? $parent->parent : $parent)?->getFirstToken()?->text);
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
				&& $expression->precedence <= $parent->precedence
			)
			|| !$context->report($node, "Useless parentheses around the operand of `$keyword`, because it takes the operand without them.")
		) {
			return;
		}

		$inner = $expression->withoutEdgeTrivia();
		$node->replaceWith($inner);
		$previous = $inner->getFirstToken()->getPrevious();
		if ($previous?->getTrailingSpace() === '') {
			$previous->setTrailingTrivia([Trivia::fromText(' ')]);
		}
	}


	/** Whether a comment stands between the parentheses and what they hold, which dropping them would lose. */
	private static function holdsComment(ParenthesizedNode $node): bool
	{
		return $node->openParen->hasComment()
			|| $node->closeParen->hasComment()
			|| $node->expression->hasLeadingComment()
			|| $node->expression->hasTrailingComment();
	}
}
