<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NativeType;
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\Member\ClassConstNode;
use function count;


/**
 * A class constant declares the native type of its value, which the value it is written as gives, no annotation
 * saying what a constant is: `const string NAME = 'a'`. A constant declared together with others and one whose value
 * shows no type, such as another constant, stays as it is.
 *
 * A constant nothing can declare again, a private or a final one, or one of a final class, an anonymous class or an
 * enum, gets its type without risk. Any other is risky by decision rather than left alone, although a child declaring
 * it again with a value of another type fails to compile and not to run: a project asking for typed constants moves
 * its classes to them together.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.3'])]
final class ConstantTypeRequiredRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('types.declaration.constant', Domain::state('required'), 'A class constant declares the type of its value')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassConstNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassConstNode || $node->type !== null || count($items = $node->items->getItems()) !== 1) {
			return;
		}

		$item = $items[0];
		$native = NativeType::fromValue($item->value);
		$overridable = $node->isOverridable();
		if (
			$native === null
			|| !$context->report(
				$item,
				"Constant `{$item->name->text}` must have the native type `$native` of its value.",
				risk: $overridable ? Risk::BehaviorChanges : null,
				because: $overridable ? 'a child declaring the constant again must keep its type' : null,
			)
		) {
			return;
		}

		$node->type = (new Builder)->type($native)->setEdgeTrivia(trailing: [Trivia::fromText(' ')]);
	}
}
