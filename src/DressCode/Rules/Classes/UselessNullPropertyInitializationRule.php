<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Token;


/**
 * An untyped property is not initialized with null explicitly: that is its default anyway.
 * A typed property keeps `= null`, because without it the property would be uninitialized.
 */
#[RuleInfo(
	'dresscode/useless-null-property-initialization',
	Stage::Structure,
	description: 'Removes the explicit null initialization of untyped properties',
)]
final class UselessNullPropertyInitializationRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode || $node->type !== null) {
			return;
		}

		foreach ($node->items->getItems() as $item) {
			$default = $item->default;
			if (
				!$default instanceof ConstantFetchNode
				|| strcasecmp($default->name->getName(), 'null') !== 0
				|| $item->equals === null
				|| !$context->report($default, 'Useless initialization with null, an untyped property is null by default')
			) {
				continue;
			}

			$item->setDefault(null);
			$item->setEquals(null);
			$item->name->setTrailingTrivia([]);
		}
	}
}
