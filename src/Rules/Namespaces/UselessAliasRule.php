<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\UseItemNode;


/**
 * No alias that repeats the last part of the imported name: `use Foo\Bar as Bar;` is `use Foo\Bar;`.
 */
#[RuleInfo(
	'dresscode/useless-alias',
	Stage::Structure,
	description: 'Removes an import alias equal to the imported name',
	group: Group::Cleanup,
)]
final class UselessAliasRule extends NodeRule
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

		if (
			$node->alias->token->text !== $node->name->shortName
			|| !$context->report($node->alias, 'The alias repeats the imported name')
		) {
			return;
		}

		$trailing = $node->alias->token->trailingTrivia;
		$node->alias = null;
		$node->asKeyword = null;
		$node->name->token->removeTrailingWhitespace();
		$node->name->token->setTrailingTrivia([...$node->name->token->trailingTrivia, ...$trailing]);
	}
}
