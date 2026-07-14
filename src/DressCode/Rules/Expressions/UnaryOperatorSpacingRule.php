<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\PostfixNode;
use PhpSyntax\Nodes\Expression\UnaryNode;
use PhpSyntax\Token;


/**
 * A unary operator adjacent to its operand: `!$a`, `-$a`, `$a++`.
 */
#[RuleInfo(
	'dresscode/unary-operator-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between a unary operator and its operand',
)]
final class UnaryOperatorSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [UnaryNode::class, PostfixNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$operator, $token] = match (true) {
			$node instanceof UnaryNode => [$node->operator, $node->operator],
			$node instanceof PostfixNode => [$node->operator, $node->operator->getPrevious()],
			default => [null, null],
		};
		$space = $token?->getTrailingSpace();
		if ($operator === null || $token === null || $space === null || $space === '') {
			return;
		}

		if ($context->report($operator, "No whitespace between the {$operator->text} operator and its operand")) {
			$token->setTrailingSpace('');
		}
	}
}
