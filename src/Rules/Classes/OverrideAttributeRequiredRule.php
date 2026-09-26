<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use function in_array;


/**
 * A method that overrides one of a parent class or implements one of an interface carries `#[\Override]` of
 * PHP 8.3, which makes PHP check that it still overrides something: a parent that renames or drops the method
 * then turns a silent new method into an error.
 *
 * What the method overrides the types of the code say, so the rule runs only where the configuration gives
 * them. A constructor and a destructor are left alone, a child declaring them without regard for the parent,
 * and so is a method of a trait, which overrides whatever the class using it has.
 */
#[RuleInfo(
	'dresscode/override-attribute-required',
	Stage::Structure,
	description: 'Marks a method overriding an inherited one with #[\Override]',
	group: Group::Modernization,
	requires: ['php' => '>=8.3'],
	requiresTypes: true,
)]
final class OverrideAttributeRequiredRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| in_array(strtolower($node->name->text), ['__construct', '__destruct'], true)
			|| $node->findAncestor(TraitNode::class) !== null
			|| self::isMarked($node)
		) {
			return;
		}

		$overridden = $context->getAnalysis(Types::class)->findOverridden($node);
		if (
			$overridden === null
			|| !$context->report($node->name, 'The method overriding ' . $overridden->declaringClass . '::' . $overridden->name . '() must be marked with #[\Override]')
		) {
			return;
		}

		CodeWriter::addAttributes($node, $node->attributes, ['\Override'], $context);
	}


	/** Whether the method carries the attribute already, whichever way its name is written. */
	private static function isMarked(MethodNode $method): bool
	{
		return array_any(
			$method->attributes->getItems(),
			fn(Node $group) => preg_match('~(^|\W)Override\b~i', $group->text) === 1,
		);
	}
}
