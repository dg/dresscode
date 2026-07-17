<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\EmptyNode;
use PhpSyntax\Nodes\Expression\ExitNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\UnsetNode;
use PhpSyntax\Token;


/**
 * No whitespace between the name of a function and its parentheses, in calls and declarations alike;
 * `isset`, `unset`, `empty`, `list` and `exit` count as names too.
 */
#[RuleInfo(
	'dresscode/function-name-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between a function name and its parentheses',
)]
final class FunctionNameSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [
			FunctionCallNode::class, MethodCallNode::class, StaticCallNode::class, NewNode::class, ExitNode::class,
			IssetNode::class, UnsetNode::class, EmptyNode::class, ListNode::class,
			FunctionNode::class, MethodNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$paren = match (true) {
			$node instanceof FunctionCallNode, $node instanceof MethodCallNode, $node instanceof StaticCallNode => $node->args->openParen,
			$node instanceof NewNode, $node instanceof ExitNode => $node->args?->openParen,
			$node instanceof IssetNode, $node instanceof UnsetNode, $node instanceof EmptyNode, $node instanceof ListNode,
			$node instanceof FunctionNode, $node instanceof MethodNode => $node->openParen,
			default => null,
		};
		$previous = $paren?->getPrevious();
		$space = $previous?->getTrailingSpace();
		if (
		    $paren !== null
		    && $previous !== null
		    && $space !== null
		    && $space !== ''
		    && $context->report($paren, 'No whitespace between a function name and its parentheses')
		) {
			$previous->setTrailingSpace('');
		}
	}
}
