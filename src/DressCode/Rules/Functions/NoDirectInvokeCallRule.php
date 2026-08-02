<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * An invokable object is called directly: `$handler($a)`, not `$handler->__invoke($a)`; an object held in
 * a property or returned by a call gets parentheses, `($this->handler)($a)`, so that PHP does not read
 * a method call.
 */
#[RuleInfo(
	'dresscode/no-direct-invoke-call',
	Stage::Structure,
	description: 'Calls an invokable object directly instead of its __invoke() method',
)]
final class NoDirectInvokeCallRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [MethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodCallNode
			|| !$node->operator->is('->')
			|| !$node->name instanceof IdentifierNode
			|| strcasecmp($node->name->token->text, '__invoke') !== 0
			|| $node->object->getLastToken()?->hasCommentUpTo($node->args->openParen) !== false
			|| !$context->report($node->name, 'An invokable object must be called directly, not through __invoke()')
		) {
			return;
		}

		$object = clone $node->object;
		$object->getFirstToken()?->setLeadingTrivia([]);
		$object->getLastToken()?->setTrailingTrivia([]);
		$call = (new Parser)->parseExpression($object instanceof VariableNode ? '$f()' : '(0)()');
		assert($call instanceof FunctionCallNode);
		($call->name instanceof ParenthesizedNode ? $call->name->expr : $call->name)->replaceWith($object);
		$call->setArgs(clone $node->args);
		$node->replaceWith($call);
	}
}
