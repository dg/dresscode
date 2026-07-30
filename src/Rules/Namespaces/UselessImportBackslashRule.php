<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{NameForm, Node, Token};
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\UseItemNode;


/**
 * Imported names without the leading backslash: `use Foo\Bar;`, not `use \Foo\Bar;`.
 */
#[RuleInfo(
	'dresscode/uselessImportBackslash',
	Stage::Structure,
	description: 'Removes the leading backslash from imported names',
	group: RuleGroup::Cleanup,
)]
final class UselessImportBackslashRule extends NodeRule
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
				$name->form === NameForm::FullyQualified
				&& $context->report($name, 'An import must not start with a backslash')
			) {
				$name->text = substr($name->token->text, 1);
			}
		}
	}
}
