<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, AssignmentNode, PropertyFetchNode};
use PhpSyntax\Nodes\IdentifierNode;
use function count;


/**
 * A property written to an object whose class does not declare it, which PHP 8.2 deprecated: the class declares it,
 * which only a human decides the type and the visibility of, so it is only reported. Only an instance of a final class
 * is told for certain, a child of any other class or an implementation of an interface may declare the property;
 * a class allowing dynamic properties (`#[AllowDynamicProperties]`, `stdClass`, `__set()`) is left alone.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class])]
final class NoDynamicPropertiesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.php.dynamicProperty',
			Domain::state('forbidden'),
			'A property written to an object whose class does not declare it, deprecated by PHP 8.2, which is only reported',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [AssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$target = $node instanceof AssignmentNode ? $node->target : null;
		while ($target instanceof ArrayAccessNode) { // `$o->items[] = 1` creates the property too
			$target = $target->expression;
		}

		if (!$target instanceof PropertyFetchNode || !$target->name instanceof IdentifierNode) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findMemberAccess($target);
		$class = $access !== null && !$access->declared && count($access->classes) === 1 ? $access->classes[0] : null;
		if (
			$class !== null
			&& $types->isFinalClass($class) === Tristate::Yes
			&& $types->allowsDynamicProperties($class) === Tristate::No
		) {
			$context->report(
				$target->name,
				"Property `\${$target->name->text}` is not declared in `$class`, which PHP 8.2 deprecated creating it dynamically.",
				fixable: false,
			);
		}
	}
}
