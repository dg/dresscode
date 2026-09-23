<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};


/**
 * A class or an enum that is not abstract implements every abstract method it inherits, from a parent class or an
 * interface, which PHP refuses the moment it loads it otherwise. It is what a library does in a major version when
 * an interface gains a method or a method of a parent turns abstract, so the class of the project was complete
 * until the upgrade; what the method has to do nobody but the author knows, so the rule only reports. What the
 * class inherits the types of the code say, a method a trait brings counting as implemented.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, NameResolver::class])]
final class NoUnimplementedAbstractMethodsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.unimplementedAbstractMethods', Domain::state('forbidden'), 'An inherited abstract method a class that is not abstract leaves unimplemented, typically after a library added it, which is only reported')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class, EnumNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof EnumNode && !$node instanceof AnonymousClassNode)
			|| ($node instanceof ClassNode && $node->modifiers->abstract)
		) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$class = $node instanceof AnonymousClassNode
			? ($node->parent instanceof NewNode ? $types->findClasses($node->parent)[0] ?? null : null)
			: (string) $context->getAnalysis(NameResolver::class)->getDeclaredName($node);
		foreach ($class === null ? [] : $types->findUnimplementedMethods($class) as $method) {
			$context->report(
				$node instanceof AnonymousClassNode ? $node->classKeyword : $node->name,
				match (true) {
					$node instanceof EnumNode => "Enum `{$node->name->text}`",
					$node instanceof ClassNode => "Class `{$node->name->text}`",
					default => 'Anonymous class',
				} . " does not implement the abstract `{$method->declaringClass}::{$method->name}()`.",
				fixable: false,
			);
		}
	}
}
