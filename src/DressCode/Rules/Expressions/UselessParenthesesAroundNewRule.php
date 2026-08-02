<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Token;


/**
 * Since PHP 8.4 a member of a new object is accessed without parentheses around the instantiation:
 * `new Foo()->bar()`, not `(new Foo())->bar()`. The argument parentheses stay: they are what makes it work.
 */
#[RuleInfo(
	'dresscode/useless-parentheses-around-new',
	Stage::Structure,
	description: 'Removes the parentheses around new when a member of the new object is accessed',
	minPhpVersion: '8.4',
)]
final class UselessParenthesesAroundNewRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ParenthesizedNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParenthesizedNode
			|| !($new = $node->expr) instanceof NewNode
			|| $new->args === null
			|| !$node->isDereferenced()
			|| $node->openParen->hasComment()
			|| $node->closeParen->hasComment()
			|| !$context->report($node->openParen, 'Useless parentheses around new, a member is accessible without them')
		) {
			return;
		}

		$copy = clone $new;
		$copy->getFirstToken()?->setLeadingTrivia([]);
		$copy->getLastToken()?->setTrailingTrivia([]);
		$node->replaceWith($copy);
	}
}
