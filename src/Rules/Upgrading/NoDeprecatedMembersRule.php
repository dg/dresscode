<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Callee, Deprecation, MemberKind, Types};
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, StaticMethodCallNode};
use PhpSyntax\Nodes\IdentifierNode;


/**
 * Constants and methods of classes their declaration deprecates, `$order::STATUS_PAID` where `Order::STATUS_PAID` says
 * `@deprecated use Order::StatusPaid`, whatever the expression that reaches them: the member is decided by the class
 * declaring it. A replacement in the same class is fixed by writing its name, where the class has it and it takes the
 * use as it is, a method every call of the deprecated one; any other is reported with what the deprecation says.
 */
#[RuleInfo(
	'dresscode/no-deprecated-members',
	Stage::Structure,
	description: 'Replaces deprecated constants and methods with the member their deprecation names, and reports the rest',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class NoDeprecatedMembersRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassConstantFetchNode::class, MethodCallNode::class, StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstantFetchNode
			&& !$node instanceof MethodCallNode
			&& !$node instanceof StaticMethodCallNode
		) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$callee = $types->findCallee($node);
		if ($callee === null) {
			return;
		}

		$deprecation = $types->getDeprecation($callee);
		if ($deprecation === null) {
			return;
		}

		$replacement = self::findReplacement($callee, $deprecation, $types);
		$message = $callee->describe() . ' is deprecated' . NodeHelpers::formatDeprecation($deprecation);
		if ($replacement === null) {
			$context->report($node->name, $message, fixable: false);
		} elseif ($context->report($node->name, $message) && $node->name instanceof IdentifierNode) {
			$node->name->text = $replacement;
		}
	}


	/** The name written in place of the member: what the deprecation names when it is a member of the same class that can replace it. */
	private static function findReplacement(Callee $callee, Deprecation $deprecation, Types $types): ?string
	{
		$class = $deprecation->replacementClass;
		$shortName = substr($callee->declaringClass, (int) strrpos('\\' . $callee->declaringClass, '\\'));
		return $deprecation->replacementName !== null
			&& !str_starts_with($deprecation->replacementName, '$')
			&& $deprecation->replacementIsCall === ($callee->kind !== MemberKind::Constant)
			&& ($class === null || strcasecmp($class, $callee->declaringClass) === 0 || strcasecmp($class, $shortName) === 0)
			&& $types->canReplace($callee, $deprecation->replacementName)
			? $deprecation->replacementName
			: null;
	}
}
