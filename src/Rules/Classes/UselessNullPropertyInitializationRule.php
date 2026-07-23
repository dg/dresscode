<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * An untyped property is not initialized with null explicitly: that is its default anyway.
 * A typed property keeps `= null`, because without it the property would be uninitialized, and so does one
 * with a comment in the initialization.
 */
#[RuleInfo(
	'dresscode/useless-null-property-initialization',
	Stage::Structure,
	description: 'Removes the explicit null initialization of untyped properties',
	group: Group::Cleanup,
)]
final class UselessNullPropertyInitializationRule extends NodeRule
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
				!$default instanceof NullNode
				|| $item->equals === null
				|| $item->hasComment()
				|| array_any($default->getLastToken()->trailingTrivia ?? [], fn(Trivia $trivia) => $trivia->isComment())
				|| !$context->report($default, 'Useless initialization with null, an untyped property is null by default')
			) {
				continue;
			}

			$item->default = null;
			$item->equals = null;
			$item->name->setTrailingTrivia([]);
		}
	}
}
