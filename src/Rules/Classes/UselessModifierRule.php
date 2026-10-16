<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Visibility};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, ParameterNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyHookNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};
use function count;


/**
 * A modifier PHP implies anyway is dropped: `final` on a member of a final class, a property and a hook among them, on
 * a method or a constant of an enum, on a private method other than the constructor, which still forbids a child one of
 * its own, on a property PHP writes privately and on a hook of a final property; `readonly` on a property of a readonly
 * class; and a set visibility PHP implies, `protected(set)` of a public readonly property and a set visibility equal to
 * the visibility of a property that is not readonly. A property and a promoted parameter keep one modifier, without
 * which the one would not parse and the other would be no property, and of two that cannot both go `readonly` stays.
 * An anonymous class is no final one, `class_alias()` letting a class extend it.
 */
#[RuleInfo(Stage::Structure)]
final class UselessModifierRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'classes.modifiers.implied',
			Domain::state('forbidden'),
			'A modifier PHP implies anyway, `final` in a final class, `readonly` in a readonly class, `protected(set)` of a public readonly property',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, ClassConstNode::class, PropertyNode::class, ParameterNode::class, PropertyHookNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof MethodNode
				&& !$node instanceof ClassConstNode
				&& !$node instanceof PropertyNode
				&& !$node instanceof ParameterNode
				&& !$node instanceof PropertyHookNode)
			|| ($node instanceof ParameterNode && !$node->promoted)
		) {
			return;
		}

		$tokens = $node->modifiers->getTokens();
		$implied = [];
		foreach ($tokens as $token) {
			$reason = self::findReason($node, $token);
			if ($reason !== null) {
				$implied[] = [$token, $reason];
			}
		}

		// a property and a promoted parameter keep a modifier, `readonly` rather than a set visibility
		if (($node instanceof PropertyNode || $node instanceof ParameterNode) && count($implied) >= count($tokens)) {
			$implied = array_values(array_filter($implied, fn(array $pair) => !$pair[0]->is(Token::Readonly)));
			if (count($implied) >= count($tokens)) {
				array_pop($implied);
			}
		}

		foreach ($implied as [$token, $reason]) {
			if ($context->report($token, "Useless `$token->text` modifier, because $reason.")) {
				$node->modifiers->removeToken($token);
			}
		}
	}


	/** Why PHP implies the modifier on the declaration; null where it does not. */
	private static function findReason(
		MethodNode|ClassConstNode|PropertyNode|ParameterNode|PropertyHookNode $node,
		Token $token,
	): ?string
	{
		$class = $node->findAncestor(ClassLikeNode::class);
		$readonlyClass = ($class instanceof ClassNode || $class instanceof AnonymousClassNode) && $class->modifiers->readonly;
		$modifiers = $node->modifiers;
		if ($token->is(Token::Final)) {
			return match (true) {
				$class instanceof ClassNode && $class->modifiers->final => 'the class is final',
				$class instanceof EnumNode && !$node instanceof PropertyHookNode => 'an enum is final',
				$node instanceof MethodNode && $modifiers->visibility === Visibility::Private && !$node->isConstructor()
					=> 'no child overrides a private method',
				($node instanceof PropertyNode || $node instanceof ParameterNode) && $modifiers->writeVisibility === Visibility::Private
					=> 'PHP makes a property it writes privately final',
				$node instanceof PropertyHookNode && (self::findHookedProperty($node)?->modifiers->final ?? false)
					=> 'the property is final',
				default => null,
			};

		} elseif ($token->is(Token::Readonly)) {
			return ($node instanceof PropertyNode || $node instanceof ParameterNode) && $readonlyClass
				? 'the class is readonly'
				: null;

		} elseif (
			($node instanceof PropertyNode || $node instanceof ParameterNode)
			&& $token->is([Token::PublicSet, Token::ProtectedSet, Token::PrivateSet])
		) {
			$readonly = $readonlyClass || $modifiers->readonly;
			$written = match (true) {
				$token->is(Token::PublicSet) => Visibility::Public,
				$token->is(Token::ProtectedSet) => Visibility::Protected,
				default => Visibility::Private,
			};
			return match (true) {
				$readonly && $written === Visibility::Protected && $modifiers->visibility === Visibility::Public
					=> 'PHP writes a public readonly property as protected',
				!$readonly && $written === $modifiers->visibility => 'the property is written as it is read',
				default => null,
			};
		}

		return null;
	}


	/** The property or the promoted parameter the hook belongs to. */
	private static function findHookedProperty(PropertyHookNode $hook): PropertyNode|ParameterNode|null
	{
		$owner = $hook->findAncestor(PropertyNode::class) ?? $hook->findAncestor(ParameterNode::class);
		return $owner instanceof PropertyNode || $owner instanceof ParameterNode ? $owner : null;
	}
}
