<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
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
final class UselessConstructParenthesesRule extends Rule
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
			$parent instanceof YieldNode, $parent instanceof BreakNode, $parent instanceof ContinueNode,
			$parent instanceof IncludeNode, $parent instanceof CaseNode => true,
			$parent instanceof SeparatedNodeList => $parent->parent instanceof EchoNode,
			default => false,
		};
		if (!$unneeded || !$context->report($node, 'Useless parentheses around the operand')) {
			return;
		}

		$inner = clone $node->expr;
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
}
