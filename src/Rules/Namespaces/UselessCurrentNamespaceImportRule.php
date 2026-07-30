<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\Statement\UseNode;


/**
 * No import of a name from the namespace of the file itself: it resolves the same without the import. In the global
 * namespace that holds for every kind, and PHP warns about such an import; in a named namespace an import of a
 * function or a constant stays, because it says the symbol is the namespace's own, which an unqualified name that
 * falls back to the global one does not.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class UselessCurrentNamespaceImportRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('imports.ofCurrentNamespace', Domain::state('forbidden'), 'An import of a class of the namespace it stands in, `use Acme\\Shop\\Order;` inside `namespace Acme\\Shop`')];
	}


	public function getVisitedNodes(): array
	{
		return [UseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UseNode) {
			return;
		}

		$namespace = $context->getAnalysis(NameResolver::class)->getNamespace($node);
		foreach ($node->items->getItems() as $item) {
			$parts = explode('\\', $item->fullName); // the prefix of a group belongs to the name of its item
			$last = array_pop($parts);
			$shown = $item->symbolKind === SymbolKind::Function ? "$last()" : $last;
			if (
				$item->alias !== null
				|| ($namespace !== '' && $item->symbolKind !== SymbolKind::ClassLike)
				|| strcasecmp(implode('\\', $parts), $namespace) !== 0
				|| !$context->report($item, "Useless import of `$shown`, because it is in the current namespace.")
			) {
				continue;
			}

			$item->remove();
		}
	}
}
