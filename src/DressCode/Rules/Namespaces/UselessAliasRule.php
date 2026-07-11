<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\Token;


/**
 * No alias that repeats the last part of the imported name: `use Foo\Bar as Bar;` is `use Foo\Bar;`.
 */
#[RuleInfo(
	'dresscode/useless-alias',
	Stage::Structure,
	description: 'Removes an import alias equal to the imported name',
)]
final class UselessAliasRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [UseItemNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UseItemNode || $node->alias === null || $node->asKeyword === null) {
			return;
		}

		$parts = $node->name->getParts();
		if (
		    $node->alias->token->text !== end($parts)
		    || !$context->report($node->alias, 'The alias repeats the imported name')
		) {
			return;
		}

		$trailing = $node->alias->token->trailingTrivia;
		$node->setAlias(null);
		$node->setAsKeyword(null);
		$node->name->token->removeTrailingWhitespace();
		$node->name->token->setTrailingTrivia([...$node->name->token->trailingTrivia, ...$trailing]);
	}
}
