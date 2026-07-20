<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Visibility};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, ParameterNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};
use function count;


/**
 * A modifier the enclosing class already implies is dropped: `final` on a method or a constant of a final
 * class or of an enum, `readonly` on a property or a promoted parameter of a readonly class, unless it is the only
 * modifier, without which a parameter would not be promoted and a property would not parse. `final` goes from
 * a private method as well, which no class can override anyway; the constructor keeps it, because there final
 * still forbids a child one of its own.
 */
#[RuleInfo(Stage::Structure)]
final class UselessModifierRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('classes.impliedModifiers', Domain::state('forbidden'), 'A modifier the class already implies, `final` in a final class')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, ClassConstNode::class, PropertyNode::class, ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$class = $node->findAncestor(ClassLikeNode::class);
		if ($node instanceof MethodNode || $node instanceof ClassConstNode) {
			[$kind, $message] = match (true) {
				$class instanceof ClassNode && $class->modifiers->final => [Token::Final, 'Useless `final` modifier, because the class is final.'],
				$class instanceof EnumNode => [Token::Final, 'Useless `final` modifier, because an enum is final.'],
				$node instanceof MethodNode && self::isFinalPrivateMethod($node) => [Token::Final, 'Useless `final` modifier, because no child overrides a private method.'],
				default => [null, null],
			};
		} elseif ($node instanceof PropertyNode || $node instanceof ParameterNode) {
			[$kind, $message] = ($class instanceof ClassNode || $class instanceof AnonymousClassNode) && $class->modifiers->readonly
				? [Token::Readonly, 'Useless `readonly` modifier, because the class is readonly.']
				: [null, null];
		} else {
			return;
		}

		if (
			$kind === null
			|| $message === null
			|| ($kind === Token::Readonly && count($node->modifiers->getTokens()) === 1)
		) {
			return;
		}

		foreach ($node->modifiers->getTokens() as $token) {
			if ($token->is($kind) && $context->report($token, $message)) {
				$node->modifiers->removeToken($token);
			}
		}
	}


	/** Whether the method is private and final, the constructor aside. */
	private static function isFinalPrivateMethod(MethodNode $method): bool
	{
		return $method->modifiers->visibility === Visibility::Private
			&& $method->modifiers->final
			&& !$method->isConstructor();
	}
}
