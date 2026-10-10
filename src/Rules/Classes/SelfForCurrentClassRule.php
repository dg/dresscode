<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, AnonymousFunctionNode, ClassLikeNode, NameNode};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, InstanceofNode, NewNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};
use function count;


/**
 * Inside a class, the class refers to itself as `self`, not by its own name: `self::create()`, `new self`.
 * A static method call is a risky fix: `self::` forwards late static binding, so a method using `static` sees
 * the subclass the calling method runs through instead of this class; a final class has none. A closure rebound to another scope,
 * where `self` means that scope, is out of sight of the rule.
 *
 * With `qualification.staticInFinalClass`, where no subclass can exist, in a final class, an anonymous class and
 * an enum, `static` means this class too and is written `self`: `static::create()`, `new static`,
 * `$x instanceof static`; the return type `static` stays, since it says what a subclass would return. Inside a
 * closure the fix is risky, because a closure bound to an object of another class and a scope of a third one sees
 * `static` and `self` as two different classes.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class SelfForCurrentClassRule extends NodeRule
{
	private const OwnName = 'qualification.currentClass';
	private const Static = 'qualification.staticInFinalClass';

	private bool $ownName = true;

	private bool $onStatic = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::OwnName, new Words(['self' => 'the class named `self` inside itself']), 'How a class refers to itself in an expression: a static access, an instantiation, an `instanceof`'),
			new Decision(
				self::Static,
				new Words(['self' => 'written `self`, `self::create()`, `new self`']),
				'`static` in a class no subclass can extend: a final class, an anonymous class, an enum',
				notes: ['The return type `static` stays, saying what a subclass would return.'],
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->ownName = !$values->isKept(self::OwnName);
		$this->onStatic = !$values->isKept(self::Static);
	}


	public function getVisitedNodes(): array
	{
		return $this->onStatic
			? [ClassNode::class, AnonymousClassNode::class, EnumNode::class]
			: [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof ClassNode && $this->ownName) {
			$this->replaceOwnName($node, $context);
		}

		if (!$this->onStatic) {
			return;
		}

		if (
			$node instanceof AnonymousClassNode
			|| $node instanceof EnumNode
			|| ($node instanceof ClassNode && $node->modifiers->final)
		) {
			$this->replaceStatic($node, $context);
		}
	}


	private function replaceOwnName(ClassNode $class, RuleContext $context): void
	{
		$own = $class->name->token->text;
		$resolver = $context->getAnalysis(NameResolver::class);
		$ownFullName = (string) $resolver->getDeclaredName($class);
		foreach ($class->find(NameNode::class) as $name) {
			$parts = $name->parts;
			$risky = $name->parent instanceof StaticMethodCallNode && !$class->modifiers->final;
			if (
				count($parts) !== 1
				|| strcasecmp($parts[0], $own) !== 0
				|| strcasecmp($resolver->resolveClass($name), $ownFullName) !== 0
				|| !self::isClassReference($name)
				|| $name->findClassScope() !== $class
				|| !$context->report(
					$name,
					"The current class `$own` must be referenced as `self`.",
					decision: self::OwnName,
					risk: $risky ? Risk::BehaviorChanges : null,
					because: $risky ? 'a method called through `self::` sees the subclass as `static`' : null,
				)
			) {
				continue;
			}

			$name->text = 'self';
		}
	}


	private function replaceStatic(ClassLikeNode&Node $class, RuleContext $context): void
	{
		foreach ($class->find(NameNode::class) as $name) {
			if (
				strcasecmp($name->text, 'static') !== 0
				|| !self::isClassReference($name)
				|| $name->findClassScope() !== $class
			) {
				continue;
			}

			$risky = self::isInClosure($name, $class);
			if (!$context->report(
				$name,
				'`static` must be written `self`, because no subclass can exist here.',
				decision: self::Static,
				risk: $risky ? Risk::BehaviorChanges : null,
				because: $risky ? 'a closure bound to another class sees `static` and `self` apart' : null,
			)) {
				continue;
			}

			$name->text = 'self';
		}
	}


	/** Whether the name stands for a class in an expression: a static access, an instantiation, an `instanceof`. */
	private static function isClassReference(NameNode $name): bool
	{
		$parent = $name->parent;
		return $parent instanceof StaticMethodCallNode
			|| $parent instanceof ClassConstantFetchNode
			|| $parent instanceof StaticPropertyFetchNode
			|| $parent instanceof NewNode
			|| $parent instanceof InstanceofNode;
	}


	private static function isInClosure(NameNode $name, Node $class): bool
	{
		for ($node = $name->parent; $node !== null && $node !== $class; $node = $node->parent) {
			if ($node instanceof AnonymousFunctionNode) {
				return true;
			}
		}

		return false;
	}
}
