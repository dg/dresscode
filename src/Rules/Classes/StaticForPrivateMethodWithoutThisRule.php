<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{MethodCallNode, StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use function in_array;


/**
 * A private method that uses no `$this` is `static`, which says it needs no object, and the class calls it as
 * `self::method()`; a method that only called such methods through `$this` becomes one in the next round.
 *
 * The method is left alone where the static context could change what its body does: `$this` anywhere in it, a
 * closure that inherits it included and an anonymous class that has its own excluded, a variable variable,
 * `compact()`, `extract()`, `get_defined_vars()`, eval and include, which could reach it by name,
 * `debug_backtrace()`, which shows the object, `parent::` and a call through `self::`, `static::` or the name of
 * the class or an ancestor of a method the class does not declare static, which PHP makes with the object; any
 * class a class extending another names may be an ancestor. A method calling itself through `$this` stays too.
 *
 * Every fix is risky, because what the file does not show can notice the change: a closure made of the method is
 * static and refuses to be bound to an object, and reflection reports it static.
 */
#[RuleInfo(
	'dresscode/static-for-private-method-without-this',
	Stage::Structure,
	description: 'Marks a private method that does not use $this as static',
	risky: true,
)]
final class StaticForPrivateMethodWithoutThisRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode && !$node instanceof AnonymousClassNode) {
			return;
		}

		foreach ($node->members as $method) {
			if (
				!$method instanceof MethodNode
				|| !$this->canBeStatic($method, $node, $context)
				|| !$context->report($method->name, "The private method {$method->name->text}() uses no \$this and must be static")
			) {
				continue;
			}

			$token = new Token(TokenKind::Static, 'static');
			$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$method->modifiers->append($token);
			$this->replaceCalls($node, $method->name->text);
		}
	}


	private function canBeStatic(MethodNode $method, ClassNode|AnonymousClassNode $class, RuleContext $context): bool
	{
		if (
			!$method->modifiers->isPrivate()
			|| $method->modifiers->isStatic()
			|| $method->body === null
			|| str_starts_with($method->name->text, '__')
			|| NodeHelpers::findDynamicVariableAccesses($method->body, $context) !== []
		) {
			return false;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($method->body->find(Node::class) as $inner) {
			if ($inner->findAncestor(ClassLikeNode::class) !== $class) {
				continue; // what an anonymous class inside does is its own
			}

			if (
				($inner instanceof VariableNode && $inner->isThis())
				|| ($inner instanceof StaticMethodCallNode && !$this->canCallWithoutObject($inner, $class, $context))
				|| $resolver->isGlobalFunctionCall($inner, 'debug_backtrace')
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether the static call does the same in a static method, where it has no object to pass on. */
	private function canCallWithoutObject(StaticMethodCallNode $call, ClassNode|AnonymousClassNode $class, RuleContext $context): bool
	{
		if (!$call->class instanceof NameNode) {
			return true;
		} elseif (strcasecmp($call->class->text, 'parent') === 0 || !$call->name instanceof IdentifierNode) {
			return false;
		} elseif (!in_array(strtolower($call->class->text), ['self', 'static'], true)) {
			$resolver = $context->getAnalysis(NameResolver::class);
			$named = $resolver->resolveClass($call->class);
			$own = $resolver->getDeclaredName($class);
			if ($own === null || strcasecmp($named, $own) !== 0) {
				return $class->extends === null;
			}
		}

		foreach ($class->members as $member) {
			if ($member instanceof MethodNode && strcasecmp($member->name->text, $call->name->text) === 0) {
				return $member->modifiers->isStatic();
			}
		}

		return false;
	}


	/** Rewrites every `$this->method()` of the class, an anonymous class inside it aside, to `self::method()`. */
	private function replaceCalls(ClassNode|AnonymousClassNode $class, string $method): void
	{
		foreach ($class->find(MethodCallNode::class) as $call) {
			if (
				$call->findAncestor(ClassLikeNode::class) !== $class
				|| $call->isNullsafe()
				|| !$call->object instanceof VariableNode
				|| $call->object->plainName !== 'this'
				|| !$call->name instanceof IdentifierNode
				|| strcasecmp($call->name->text, $method) !== 0
				|| $call->object->getLastToken()?->hasCommentUpTo($call->arguments->openParen) !== false
			) {
				continue;
			}

			$call->replaceWith(StaticMethodCallNode::of(NameNode::fromText('self'), $method, clone $call->arguments));
		}
	}
}
