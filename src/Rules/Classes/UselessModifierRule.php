<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\Scope;
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\{AnonymousClassNode, ParameterNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};
use function count;


/**
 * A modifier the enclosing class already implies is dropped: `final` on a method or a constant of a final
 * class or of an enum, `readonly` on a property or a promoted parameter of a readonly class, unless it is the only
 * modifier of the parameter, which is what makes the parameter a property. `final` goes from a private method
 * as well, which no class can override anyway; the constructor keeps it, because there final still forbids
 * a child one of its own.
 */
#[RuleInfo(
	'dresscode/useless-modifier',
	Stage::Structure,
	description: 'Removes a member modifier the class already implies',
	group: Group::Cleanup,
)]
final class UselessModifierRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class, ClassConstNode::class, PropertyNode::class, ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$class = $context->getAnalysis(Scope::class)->getClass($node);
		if ($node instanceof MethodNode || $node instanceof ClassConstNode) {
			[$kind, $message] = match (true) {
				$class instanceof ClassNode && $class->modifiers->isFinal() => [TokenKind::Final, 'Useless final modifier in a final class'],
				$class instanceof EnumNode => [TokenKind::Final, 'Useless final modifier in an enum'],
				$node instanceof MethodNode && self::isFinalPrivateMethod($node) => [TokenKind::Final, 'Useless final modifier on a private method'],
				default => [null, null],
			};
		} elseif ($node instanceof PropertyNode || $node instanceof ParameterNode) {
			[$kind, $message] = ($class instanceof ClassNode || $class instanceof AnonymousClassNode) && $class->modifiers->isReadonly()
				? [TokenKind::Readonly, 'Useless readonly modifier in a readonly class']
				: [null, null];
		} else {
			return;
		}

		if (
			$kind === null
			|| $message === null
			|| ($node instanceof ParameterNode && count($node->modifiers->getTokens()) === 1)
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
		return $method->modifiers->isPrivate()
			&& $method->modifiers->isFinal()
			&& strcasecmp($method->name->text, '__construct') !== 0;
	}
}
