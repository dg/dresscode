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
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};


/**
 * A class or an enum that is not abstract implements every abstract method it inherits, from a parent class or an
 * interface, which PHP refuses the moment it loads it otherwise. It is what a library does in a major version when
 * an interface gains a method or a method of a parent turns abstract, so the class of the project was complete
 * until the upgrade; what the method has to do nobody but the author knows, so the rule only reports. What the
 * class inherits the types of the code say, a method a trait brings counting as implemented.
 */
#[RuleInfo(
	'dresscode/no-unimplemented-abstract-method',
	Stage::Structure,
	description: 'Reports an abstract method a class that is not abstract inherits and does not implement',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class NoUnimplementedAbstractMethodRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, EnumNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ((!$node instanceof ClassNode && !$node instanceof EnumNode) || ($node instanceof ClassNode && $node->modifiers->isAbstract())) {
			return;
		}

		$namespace = $context->getAnalysis(NameResolver::class)->getNamespace($node);
		$class = ltrim("$namespace\\{$node->name->text}", '\\');
		foreach ($context->getAnalysis(Types::class)->findUnimplementedMethods($class) as $method) {
			$context->report($node->name, ($node instanceof EnumNode ? 'Enum' : 'Class') . " {$node->name->text} does not implement the abstract $method()", fixable: false);
		}
	}
}
