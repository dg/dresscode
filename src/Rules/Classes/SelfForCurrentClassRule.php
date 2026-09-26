<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, NameNode};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClassConstantFetchNode, ClosureNode, InstanceofNode, NewNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};
use function count;


/**
 * Inside a class, the class refers to itself as `self`, not by its own name: `self::create()`, `new self`.
 * A static method call is a risky fix: `self::` forwards late static binding, so a method using `static` sees
 * the subclass the calling method runs through instead of this class. A closure rebound to another scope,
 * where `self` means that scope, is out of sight of the rule.
 *
 * With `onStatic`, where no subclass can exist, in a final class, an anonymous class and an enum, `static` means
 * this class too and is written `self`: `static::create()`, `new static`, `$x instanceof static`; the return type
 * `static` stays, since it says what a subclass would return. Inside a closure the fix is risky, because a closure
 * bound to an object of another class and a scope of a third one sees `static` and `self` as two different classes.
 */
#[RuleInfo(
	'dresscode/self-for-current-class',
	Stage::Structure,
	description: 'Replaces the name of the current class with self',
)]
final class SelfForCurrentClassRule extends NodeRule implements ConfigurableRule
{
	private bool $onStatic = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'onStatic' => Expect::bool(false)->description('Where no subclass can exist, static means this class too and becomes self'),
		]);
	}


	public function configure(array $options): void
	{
		$this->onStatic = $options['onStatic'];
	}


	public function getVisitedTypes(): array
	{
		return $this->onStatic
			? [ClassNode::class, AnonymousClassNode::class, EnumNode::class]
			: [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof ClassNode) {
			$this->replaceOwnName($node, $context);
		}

		if (!$this->onStatic) {
			return;
		}

		if (
			$node instanceof AnonymousClassNode
			|| $node instanceof EnumNode
			|| ($node instanceof ClassNode && $node->modifiers->isFinal())
		) {
			$this->replaceStatic($node, $context);
		}
	}


	private function replaceOwnName(ClassNode $class, RuleContext $context): void
	{
		$own = $class->name->token->text;
		$resolver = $context->getAnalysis(NameResolver::class);
		$namespace = $resolver->getNamespace($class);
		$ownFullName = ($namespace === '' ? '' : $namespace . '\\') . $own;
		foreach ($class->find(NameNode::class) as $name) {
			$parts = $name->parts;
			if (
				count($parts) !== 1
				|| strcasecmp($parts[0], $own) !== 0
				|| strcasecmp($resolver->resolveClass($name), $ownFullName) !== 0
				|| !self::isClassReference($name)
				|| $name->findAncestor(ClassLikeNode::class) !== $class
				|| !$context->report($name, "The current class '$own' must be referenced as 'self'", risky: $name->parent instanceof StaticMethodCallNode)
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
				|| $name->findAncestor(ClassLikeNode::class) !== $class
				|| !$context->report(
					$name,
					"'static' can mean no class but the current one here and must be written as 'self'",
					risky: self::isInClosure($name, $class),
				)
			) {
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
			if ($node instanceof ClosureNode || $node instanceof ArrowFunctionNode) {
				return true;
			}
		}

		return false;
	}
}
