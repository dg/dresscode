<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\ClosureUseNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\AssignRefNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement\ForeachNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Token;


/**
 * The reference `&` adjacent to what it references: `&$var`, `function &f()`, `$a = &$b`.
 */
#[RuleInfo(
	'dresscode/reference-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between & and its operand',
)]
final class ReferenceSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [
			ArgumentNode::class, ParameterNode::class, ArrayItemNode::class, ClosureUseNode::class,
			ClosureNode::class, ArrowFunctionNode::class, ForeachNode::class, FunctionNode::class,
			PropertyHookNode::class, MethodNode::class, AssignRefNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$ampersand = match (true) {
			$node instanceof ArgumentNode,
			$node instanceof ParameterNode,
			$node instanceof ArrayItemNode,
			$node instanceof ClosureUseNode,
			$node instanceof ClosureNode,
			$node instanceof ArrowFunctionNode,
			$node instanceof ForeachNode,
			$node instanceof FunctionNode,
			$node instanceof PropertyHookNode,
			$node instanceof MethodNode => $node->byRef,
			$node instanceof AssignRefNode => $node->ampersand,
			default => null,
		};
		if (
			$ampersand === null
			|| ($ampersand->getTrailingSpace() ?? '') === ''
			|| !$context->report($ampersand, 'No whitespace between & and its operand')
		) {
			return;
		}

		$ampersand->setTrailingSpace('');
	}
}
