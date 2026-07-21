<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CaseNode;
use PhpSyntax\Nodes\Expression\CloneNode;
use PhpSyntax\Nodes\Expression\IncludeNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\PrintNode;
use PhpSyntax\Nodes\Expression\YieldNode;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement\BreakNode;
use PhpSyntax\Nodes\Statement\ContinueNode;
use PhpSyntax\Nodes\Statement\EchoNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * No parentheses around the whole operand of `return`, `echo`, `print`, `clone`, `yield`, `break`,
 * `continue`, `include` and the condition of a case.
 */
#[RuleInfo(
	'dresscode/useless-construct-parentheses',
	Stage::Structure,
	description: 'Removes parentheses around the operand of a language construct',
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
		if (
			!$unneeded
			|| self::holdsComment($node)
			|| !$context->report($node, 'Useless parentheses around the operand')
		) {
			return;
		}

		// every layer of a chain of parentheses goes at once: one layer per pass would need as many passes
		// as the source has layers, and a file written to nest a thousand of them would never converge
		$expression = $node->expression;
		while ($expression instanceof ParenthesizedNode && !self::holdsComment($expression)) {
			$expression = $expression->expression;
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
		foreach ($trivia as $item) {
			if ($item->isComment()) {
				return true;
			}
		}

		return false;
	}
}
