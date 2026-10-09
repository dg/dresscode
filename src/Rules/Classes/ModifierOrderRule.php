<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};


/**
 * The modifiers of a property, a method and a constant come in a fixed order: `abstract` or `final`, visibility,
 * set visibility, `static`, `readonly`.
 */
#[RuleInfo(Stage::Structure)]
final class ModifierOrderRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('classes.modifiers.order', new Words(['canonical' => '`abstract` or `final`, visibility, set visibility, `static`, `readonly`']), 'The order of the modifiers of a property, a method and a constant')];
	}


	public function getVisitedNodes(): array
	{
		return [PropertyNode::class, MethodNode::class, ClassConstNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof PropertyNode && !$node instanceof MethodNode && !$node instanceof ClassConstNode)
			|| !$node->parent?->parent instanceof ClassLikeNode
		) {
			return;
		}

		$tokens = $node->modifiers->getTokens();
		$sorted = $tokens;
		usort($sorted, fn(Token $a, Token $b) => MemberModifiers::rank($a) <=> MemberModifiers::rank($b));
		if ($sorted === $tokens) {
			return;
		}

		$desired = array_map(fn(Token $t) => $t->text, $sorted);
		$member = MemberModifiers::describeMember($node);
		if ($context->report($tokens[0], "The modifiers of the $member must be written `" . implode(' ', $desired) . '`.')) {
			MemberModifiers::write($node, $desired);
		}
	}
}
