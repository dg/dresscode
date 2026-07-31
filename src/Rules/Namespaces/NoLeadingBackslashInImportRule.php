<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{NameKind, Node, Token};
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\UseItemNode;


/**
 * Imported names without the leading backslash: `use Foo\Bar;`, not `use \Foo\Bar;`.
 */
#[RuleInfo(
	'dresscode/no-leading-backslash-in-import',
	Stage::Structure,
	description: 'Removes the leading backslash from imported names',
	group: Group::Cleanup,
)]
final class NoLeadingBackslashInImportRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [UseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UseNode) {
			return;
		}

		// a group writes the backslash once, in front of the prefix its items hang on
		$names = $node->prefix === null
			? array_map(fn(UseItemNode $item) => $item->name, $node->items->getItems())
			: [$node->prefix];
		foreach ($names as $name) {
			if (
				$name->kind === NameKind::FullyQualified
				&& $context->report($name, 'An import must not start with a backslash')
			) {
				$name->text = substr($name->token->text, 1);
			}
		}
	}
}
