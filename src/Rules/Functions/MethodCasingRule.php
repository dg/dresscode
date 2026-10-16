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
 * method a call reaches and which one a declaration overrides the types of the code say, so without them, and for a
 * receiver of no known type, only a magic method PHP names is written as PHP writes it, `__toString()`.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class])]
final class MethodCasingRule extends NodeRule
{
	/** the magic methods as PHP writes them, by their lowercased names */
	private const MagicMethods = [
		'__construct' => '__construct', '__destruct' => '__destruct', '__call' => '__call',
		'__callstatic' => '__callStatic', '__get' => '__get', '__set' => '__set', '__isset' => '__isset',
		'__unset' => '__unset', '__sleep' => '__sleep', '__wakeup' => '__wakeup', '__serialize' => '__serialize',
		'__unserialize' => '__unserialize', '__tostring' => '__toString', '__invoke' => '__invoke',
		'__set_state' => '__set_state', '__clone' => '__clone', '__debuginfo' => '__debugInfo',
	];


	public static function getDecisions(): array
	{
		return [new Decision(
			'casing.method',
			new Words(['declared' => '`$order->getTotal()`, never `$order->gettotal()`']),
			'The case of the name of a method called, as its declaration writes it, and of a method overriding another, as the overridden one writes it; a magic method of PHP as PHP writes it, the others only with the types of the code',
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
		$declared = $written === null
			? null
			: self::MagicMethods[strtolower($written)] ?? $context->findAnalysis(Types::class)?->findMember($node)?->name;
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
		$magic = self::MagicMethods[strtolower($written)] ?? null;
		$overridden = $magic === null ? $context->findAnalysis(Types::class)?->findOverridden($node) : null;
		$declared = $magic ?? $overridden?->name;
		if (
			$declared !== null
			&& $declared !== $written
			&& strcasecmp($declared, $written) === 0
			&& $context->report(
				$node->name,
				"The method `$written()` must be written `$declared()`, as "
				. ($overridden === null ? 'PHP names it' : "`$overridden->declaringClass` declares it") . '.',
			)
		) {
			$node->name->text = $declared;
		}
	}
}
