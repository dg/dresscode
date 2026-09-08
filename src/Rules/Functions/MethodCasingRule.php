<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{MethodCallNode, StaticMethodCallNode};
use PhpSyntax\Nodes\Member\MethodNode;


/**
 * A method is called in the case of its declaration, `$order->getTotal()` and never `$order->gettotal()`, which PHP
 * accepts, and a method overriding one of a parent class or an interface is declared in the case of that one. Which
 * method a call reaches and which one a declaration overrides the types of the code say, so a call on a receiver of
 * no known type stays.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class])]
final class MethodCasingRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'casing.method',
			new Words(['declared' => '`$order->getTotal()`, never `$order->gettotal()`']),
			'The case of the name of a method called, as its declaration writes it, and of a method overriding another, as the overridden one writes it',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [MethodCallNode::class, StaticMethodCallNode::class, MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof MethodNode) {
			$this->checkDeclaration($node, $context);
			return;
		} elseif (!$node instanceof MethodCallNode && !$node instanceof StaticMethodCallNode) {
			return;
		}

		$written = $node->plainName;
		$declared = $written === null ? null : $context->getAnalysis(Types::class)->findMember($node)?->name;
		if (
			$declared !== null
			&& $declared !== $written
			&& strcasecmp($declared, $written) === 0
			&& $context->report($node->name, "The method `$written()` must be written `$declared()`.")
		) {
			$node->rename($declared);
		}
	}


	private function checkDeclaration(MethodNode $node, RuleContext $context): void
	{
		$written = $node->name->text;
		$overridden = $context->getAnalysis(Types::class)->findOverridden($node);
		if (
			$overridden !== null
			&& $overridden->name !== $written
			&& strcasecmp($overridden->name, $written) === 0
			&& $context->report(
				$node->name,
				"The method `$written()` must be written `{$overridden->name}()`, as `$overridden->declaringClass` declares it.",
			)
		) {
			$node->name->text = $overridden->name;
		}
	}
}
