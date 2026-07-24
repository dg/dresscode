<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Token;


/**
 * The spread operator adjacent to its operand: `...$args`, in arguments, parameters and arrays.
 */
#[RuleInfo(
	'dresscode/spread-operator-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between ... and its operand',
)]
final class SpreadOperatorSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArgumentNode::class, ParameterNode::class, ArrayItemNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$ellipsis = match (true) {
			$node instanceof ArgumentNode,
			$node instanceof ParameterNode,
			$node instanceof ArrayItemNode => $node->ellipsis,
			default => null,
		};
		if (
			$ellipsis === null
			|| ($ellipsis->getTrailingSpace() ?? '') === ''
			|| !$context->report($ellipsis, 'No whitespace between ... and its operand')
		) {
			return;
		}

		$ellipsis->setTrailingSpace('');
	}
}
