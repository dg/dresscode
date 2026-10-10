<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\{Names, Words};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token, Visibility};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, IdentifierNode};
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Member\{MethodNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * A method that uses no `$this` is `static`, which says it needs no object, and the class calls it as
 * `self::method()`; a method that only called such methods through `$this` becomes one in the next round. Which
 * methods are meant is said by their visibility, a private one by default.
 *
 * A protected or a public method is made static only where no other class can declare it, since a child declaring
 * it again without `static`, or a parent, an interface or a trait declaring it so, is a fatal error and not a changed
 * behaviour: the method or its class must be final, or the class anonymous, and the class must extend, implement
 * and use nothing, an ancestor whose declarations are not all in sight being able to declare any method.
 *
 * The method is left alone where the static context could change what its body does: `$this` anywhere in it, a
 * closure that inherits it included and an anonymous class that has its own excluded, a variable variable,
 * `compact()`, `extract()`, `get_defined_vars()`, `eval` and `include`, which could reach it by name,
 * `debug_backtrace()`, which shows the object, `parent::` and a call through `self::`, `static::` or the name of
 * the class or an ancestor of a method the class does not declare static, which PHP makes with the object; without
 * the types, any class a class extending another names may be an ancestor. A method calling itself through `$this`
 * stays too.
 *
 * Every fix is risky, because what the file does not show can notice the change: a closure made of the method is
 * static and refuses to be bound to an object, and reflection reports it static.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class, NameResolver::class])]
final class StaticForMethodWithoutThisRule extends NodeRule
{
	private const Visibilities = 'functions.staticWithoutThis.methodVisibility';

	/** @var list<Visibility> */
	private array $visibilities = [Visibility::Private];


	public static function getDecisions(): array
	{
		return [
			new Decision('functions.staticWithoutThis.method', new Words(['required' => 'declared `static`']), 'The `static` keyword of a method that does not use `$this`, of the visibilities `functions.staticWithoutThis.methodVisibility` names'),
			new Decision(self::Visibilities, new Names([
				'private' => 'a private method',
				'protected' => 'a protected method, where no child and no ancestor can declare it',
				'public' => 'a public method, where no child and no ancestor can declare it',
			]), 'The visibilities of the methods made static', parameter: true, default: ['private']),
		];
	}


	public function configure(Values $values): void
	{
		$this->visibilities = array_map(
			fn(string $name) => match ($name) {
				'public' => Visibility::Public,
				'protected' => Visibility::Protected,
				default => Visibility::Private,
			},
			$values->get(self::Visibilities)->getNames(),
		);
	}


	public function getVisitedNodes(): array
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
				|| !$context->report($method->name, 'The ' . strtolower(($method->modifiers->visibility ?? Visibility::Public)->name) . " method `{$method->name->text}()` uses no `\$this` and must be static.", risk: Risk::BehaviorChanges, because: 'a closure made of it can no longer be bound to an object')
			) {
				continue;
			}

			$method->modifiers->append(Token::fromText('static'));
			$this->replaceCalls($node, $method->name->text);
		}
	}


	private function canBeStatic(MethodNode $method, ClassNode|AnonymousClassNode $class, RuleContext $context): bool
	{
		$visibility = $method->modifiers->visibility ?? Visibility::Public;
		return in_array($visibility, $this->visibilities, true)
			&& !$method->modifiers->static
			&& !str_starts_with($method->name->text, '__')
			&& ($visibility === Visibility::Private || self::isDeclaredOnlyHere($method, $class))
			&& !NodeHelpers::needsObject($method, $context);
	}


	/**
	 * Whether no other class can declare the method, which then could not be static here: no child, the method
	 * being final or its class final or anonymous, and no ancestor, the class extending, implementing and using
	 * nothing.
	 */
	private static function isDeclaredOnlyHere(MethodNode $method, ClassNode|AnonymousClassNode $class): bool
	{
		return !$method->isOverridable()
			&& $class->extends === null
			&& $class->implements === null
			&& !array_any($class->members->getItems(), fn(Node $member) => $member instanceof TraitUseNode);
	}


	/** Rewrites every `$this->method()` of the class, an anonymous class inside it aside, to `self::method()`. */
	private function replaceCalls(ClassNode|AnonymousClassNode $class, string $method): void
	{
		foreach ($class->find(MethodCallNode::class) as $call) {
			if (
				$call->findAncestor(ClassLikeNode::class) !== $class
				|| $call->nullsafe
				|| !$call->isOfThis()
				|| !$call->name instanceof IdentifierNode
				|| !$call->name->equals($method)
				|| $call->object->getLastToken()->hasCommentUpTo($call->arguments->openParen)
			) {
				continue;
			}

			$call->replaceWith((new Builder)->staticMethodCall('self', $method, $call->arguments));
		}
	}
}
