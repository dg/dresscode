<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Token;
use function count;


/**
 * No import of a name from the namespace of the file itself: it resolves the same without the import.
 */
#[RuleInfo(
	'dresscode/use-from-same-namespace',
	Stage::Structure,
	description: 'Removes imports of names from the current namespace',
)]
final class UseFromSameNamespaceRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [UseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UseNode || $node->type !== null) {
			return;
		}

		$namespace = $context->getAnalysis(NameResolver::class)->getNamespace($node);
		foreach ($node->items->getItems() as $item) {
			$parts = $item->name->getParts();
			$last = array_pop($parts);
			if (
				$item->alias !== null
				|| $item->type !== null
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
