<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;
use function count;


/**
 * No import of a name from the namespace of the file itself: it resolves the same without the import. In the global
 * namespace that holds for every kind, and PHP warns about such an import; in a named namespace an import of a
 * function or a constant stays, because it says the symbol is the namespace's own, which an unqualified name that
 * falls back to the global one does not.
 */
#[RuleInfo(
	'dresscode/use-from-same-namespace',
	Stage::Structure,
	description: 'Removes imports of names from the current namespace',
)]
final class UseFromSameNamespaceRule extends NodeRule
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

		$namespace = $context->getAnalysis(NameResolver::class)->getNamespace($node);
		foreach ($node->items->getItems() as $item) {
			$parts = explode('\\', $item->fullName); // the prefix of a group belongs to the name of its item
			$last = array_pop($parts);
			if (
				$item->alias !== null
				|| ($namespace !== '' && $item->kind !== SymbolKind::ClassLike)
				|| strcasecmp(implode('\\', $parts), $namespace) !== 0
				|| !$context->report($item, "The import of '$last' from the current namespace is useless")
			) {
				continue;
			}

			if (count($node->items) === 1) {
				$node->remove();
				return;
			}

			$node->items->removeItem($item);
		}
	}
}
