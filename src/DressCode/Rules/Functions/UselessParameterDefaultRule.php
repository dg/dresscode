<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use PhpSyntax\Token;


/**
 * No default value on a parameter that a required one follows; such a default can never apply.
 * A default `null` on a plain type stays, because it is what makes the type nullable, and
 * a promoted property is not touched at all.
 */
#[RuleInfo(
	'dresscode/useless-parameter-default',
	Stage::Structure,
	description: 'Removes a default value that a required parameter makes unreachable',
)]
final class UselessParameterDefaultRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| $node->equals === null
			|| $node->default === null
			|| !$node->modifiers->isEmpty()
			|| $this->keepsImplicitNullability($node)
			|| !($params = $node->parent) instanceof SeparatedNodeList
			|| !$this->hasRequiredAfter($params, $node)
			|| ($last = $node->getLastToken()) === null
			|| $node->equals->hasComment()
			|| $node->equals->hasCommentUpTo($last)
			|| $last->hasComment()
			|| !$context->report($node->default, 'Useless default value, a parameter without one follows')
		) {
			return;
		}

		$node->setEquals(null);
		$node->setDefault(null);
		$node->var->getLastToken()?->removeTrailingWhitespace();
	}


	/** Removing `= null` from a plain type would stop the parameter accepting null. */
	private function keepsImplicitNullability(ParameterNode $node): bool
	{
		return $node->type instanceof NamedTypeNode
			&& $node->default instanceof ConstantFetchNode
			&& strcasecmp($node->default->name->token->text, 'null') === 0;
	}


	/** @param SeparatedNodeList<ParameterNode> $params */
	private function hasRequiredAfter(SeparatedNodeList $params, ParameterNode $node): bool
	{
		$seen = false;
		foreach ($params->getItems() as $param) {
			if ($param === $node) {
				$seen = true;
			} elseif (
				$seen
				&& $param->equals === null
				&& $param->ellipsis === null
				&& $param->modifiers->isEmpty()
			) {
				return true;
			}
		}

		return false;
	}
}
