<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeNode;
use PhpSyntax\Token;


/**
 * An attribute without arguments is `#[Foo]`, not `#[Foo()]`.
 */
#[RuleInfo(
	'dresscode/useless-attribute-parentheses',
	Stage::Structure,
	description: 'Removes the empty parentheses after the name of an attribute',
)]
final class UselessAttributeParenthesesRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [AttributeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof AttributeNode) {
			return;
		}

		$args = $node->arguments;
		$name = $node->name->getLastToken();
		if (
			$args === null
			|| $name === null
			|| !$args->items->isEmpty()
			|| $args->openParen->hasCommentUpTo($args->closeParen)
			|| !$context->report($args, 'Useless empty parentheses after an attribute name')
		) {
			return;
		}

		$name->setTrailingTrivia([...$name->trailingTrivia, ...$args->closeParen->trailingTrivia]);
		$node->arguments = null;
	}
}
