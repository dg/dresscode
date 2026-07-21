<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * An untyped property is not initialized with null explicitly: that is its default anyway.
 * A typed property keeps `= null`, because without it the property would be uninitialized, and so does one
 * with a comment in the initialization.
 */
#[RuleInfo(Stage::Structure)]
final class UselessNullInitializationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('classes.untypedPropertyNullInitialization', Domain::state('forbidden'), '`public $a = null;` says what `public $a;` says')];
	}


	public function getVisitedNodes(): array
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
				|| $item->hasInnerComment()
				|| $default->hasTrailingComment()
				|| !$context->report($default, 'Useless initialization with `null`, because an untyped property is null by default.')
			) {
				continue;
			}

			$item->default = null;
			$item->equals = null;
			$item->name->setTrailingTrivia([]);
		}
	}
}
