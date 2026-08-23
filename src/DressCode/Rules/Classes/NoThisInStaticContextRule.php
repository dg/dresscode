<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\Scope;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;


/**
 * `$this` in a static method, a static closure or a plain function fails at runtime; reported.
 */
#[RuleInfo(
	'dresscode/no-this-in-static-context',
	Stage::Structure,
	description: 'Reports $this used where no object is available',
)]
final class NoThisInStaticContextRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof VariableNode
			|| !$node->name instanceof Token
			|| $node->name->text !== '$this'
			|| $node->dollar !== null
		) {
			return;
		}

		$scope = $context->getAnalysis(Scope::class);
		if ($scope->getFunction($node) !== null && !$scope->hasThis($node)) {
			$context->report($node, '$this is not available in a static context');
		}
	}
}
