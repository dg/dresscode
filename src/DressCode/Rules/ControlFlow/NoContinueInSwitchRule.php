<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\ContinueNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Nodes\Statement\ForeachNode;
use PhpSyntax\Nodes\Statement\ForNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\SwitchNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * A bare `continue` whose innermost enclosing structure is a `switch` acts as `break` and is written so.
 */
#[RuleInfo(
	'dresscode/no-continue-in-switch',
	Stage::Structure,
	description: 'Leaves a switch with break, never with continue',
)]
final class NoContinueInSwitchRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ContinueNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ContinueNode || $node->expr !== null) {
			return;
		}

		for ($ancestor = $node->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
			if ($ancestor instanceof SwitchNode) {
				break;
			} elseif (
				$ancestor instanceof ForNode
				|| $ancestor instanceof ForeachNode
				|| $ancestor instanceof WhileNode
				|| $ancestor instanceof DoWhileNode
				|| $ancestor instanceof FunctionNode
				|| $ancestor instanceof MethodNode
				|| $ancestor instanceof ClosureNode
				|| $ancestor instanceof ArrowFunctionNode
			) {
				return;
			}
		}

		if (
			$ancestor instanceof SwitchNode
			&& $context->report($node, "A switch must be left with 'break', not 'continue'")
		) {
			$node->replaceWith((new Parser)->parseStatement('break;'));
		}
	}
}
