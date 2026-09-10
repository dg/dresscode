<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Deprecation, Member, MemberKind, Types};
use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\IdentifierNode;
use function in_array;


/**
 * Constants, methods and properties of classes their declaration deprecates, `$order::STATUS_PAID` where
 * `Order::STATUS_PAID` says `@deprecated use Order::StatusPaid`, whatever the expression that reaches them: the member
 * is decided by the class declaring it. A replacement in the same class is fixed by writing its name, where the class
 * has it at least as visible, for a property at least as writable and of the same type, and it takes the use as it is,
 * a method every call of the deprecated one; any other is reported with what the deprecation says.
 */
#[RuleInfo(
	'dresscode/noDeprecatedMembers',
	Stage::Structure,
	description: 'Replaces deprecated constants, methods and properties with the member their deprecation names, and reports the rest',
	group: RuleGroup::Deprecations,
	typesRequired: true,
)]
final class NoDeprecatedMembersRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [
			ClassConstantFetchNode::class,
			MethodCallNode::class,
			StaticMethodCallNode::class,
			PropertyFetchNode::class,
			StaticPropertyFetchNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstantFetchNode
			&& !$node instanceof MethodCallNode
			&& !$node instanceof StaticMethodCallNode
			&& !$node instanceof PropertyFetchNode
			&& !$node instanceof StaticPropertyFetchNode
		) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$member = $types->findMember($node);
		if ($member === null) {
			return;
		}

		$deprecation = $types->findDeprecation($member);
		if ($deprecation === null) {
			return;
		}

		$replacement = self::findReplacement($member, $deprecation, $types);
		$message = $member->describe() . ' is deprecated' . $deprecation->formatDescription();
		if ($replacement === null) {
			$context->report($node->name, $message, fixable: false);
		} elseif ($context->report($node->name, $message)) {
			if ($node instanceof StaticPropertyFetchNode && $node->name instanceof Token) {
				$node->name->setText('$' . $replacement);
			} elseif ($node->name instanceof IdentifierNode) {
				$node->name->text = $replacement;
			}
		}
	}


	/** The name written in place of the member: what the deprecation names when it is a member of the same class that can replace it. */
	private static function findReplacement(Member $member, Deprecation $deprecation, Types $types): ?string
	{
		$class = $deprecation->replacementClass;
		$shortName = substr($member->declaringClass, (int) strrpos('\\' . $member->declaringClass, '\\'));
		$isProperty = in_array($member->kind, [MemberKind::Property, MemberKind::StaticProperty], true);
		$isCall = in_array($member->kind, [MemberKind::Method, MemberKind::StaticMethod], true);
		return $deprecation->replacementName !== null
			&& str_starts_with($deprecation->replacementName, '$') === $isProperty
			&& $deprecation->replacementIsCall === $isCall
			&& ($class === null || strcasecmp($class, $member->declaringClass) === 0 || strcasecmp($class, $shortName) === 0)
			&& $types->canReplace($member, ltrim($deprecation->replacementName, '$'))
			? ltrim($deprecation->replacementName, '$')
			: null;
	}
}
