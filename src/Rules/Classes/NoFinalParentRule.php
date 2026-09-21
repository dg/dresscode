<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * A class extends no final class, which PHP refuses the moment it loads the child. It is what a library does in
 * a major version to a class it marked `@final` in a minor one, so the class of the project stood on it until the
 * upgrade; nothing can be written instead, the child has to hold the class it extended or build on what the
 * library offers for extending, so the rule only reports. Whether the parent is final the types of the code say.
 */
#[RuleInfo(
	'dresscode/no-final-parent',
	Stage::Structure,
	description: 'Reports a class extending a final one',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class NoFinalParentRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode || $node->extends === null) {
			return;
		}

		$parent = $context->getAnalysis(NameResolver::class)->resolveClass($node->extends);
		if ($context->getAnalysis(Types::class)->isFinalClass($parent) === true) {
			$context->report($node->extends, "Class {$node->name->text} extends the final $parent", fixable: false);
		}
	}
}
