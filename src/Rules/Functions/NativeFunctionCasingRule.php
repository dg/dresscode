<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use function strlen;


/**
 * Functions defined by PHP called in lowercase: `strlen()`, not `StrLen()`.
 */
#[RuleInfo(
	'dresscode/native-function-casing',
	Stage::Structure,
	description: 'Calls native functions in lowercase',
)]
final class NativeFunctionCasingRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node)
		) {
			return;
		}

		$written = $node->name->shortName;
		$lower = strtolower($written);
		if ($written === $lower || !function_exists($lower) || !new \ReflectionFunction($lower)->isInternal()) {
			return;
		}

		if ($context->report($node->name, "The function $written() must be written '$lower()'")) {
			$token = $node->name->token;
			$token->setText(substr($token->text, 0, strlen($token->text) - strlen($written)) . $lower);
		}
	}
}
