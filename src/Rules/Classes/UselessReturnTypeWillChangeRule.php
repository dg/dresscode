<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, AttributeGroupNode, ClassLikeNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};
use PhpSyntax\Nodes\Type\NamedTypeNode;


/**
 * `#[\ReturnTypeWillChange]` of PHP 8.1 keeps quiet the deprecation of a method that declares no return type, or another
 * one than the method of PHP it implements will declare. On a method that declares the return type already, it says
 * nothing and goes. The rule knows the methods of the interfaces of PHP a class names in its `implements`, `Countable`,
 * `ArrayAccess`, `Iterator`, `IteratorAggregate` and `JsonSerializable`; a class that has them only from a parent is
 * left alone, the interface not being in sight.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class UselessReturnTypeWillChangeRule extends NodeRule
{
	/** interface => method => the return type PHP will declare, `mixed` taking any */
	private const ReturnTypes = [
		'Countable' => ['count' => 'int'],
		'ArrayAccess' => ['offsetexists' => 'bool', 'offsetget' => 'mixed', 'offsetset' => 'void', 'offsetunset' => 'void'],
		'Iterator' => ['current' => 'mixed', 'key' => 'mixed', 'next' => 'void', 'rewind' => 'void', 'valid' => 'bool'],
		'IteratorAggregate' => ['getiterator' => 'Traversable'],
		'JsonSerializable' => ['jsonserialize' => 'mixed'],
	];


	public static function getDecisions(): array
	{
		return [new Decision('classes.ReturnTypeWillChange', Domain::state('forbidden'), 'The attribute where the method declares the return type of what it implements')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof MethodNode || $node->returnType === null || $node->attributes->isEmpty()) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$group = array_find(
			$node->attributes->getItems(),
			fn(AttributeGroupNode $group) => $group->items->count() === 1
				&& strcasecmp($resolver->resolveClass($group->items->getItems()[0]->name), 'ReturnTypeWillChange') === 0,
		);
		$class = $node->findAncestor(ClassLikeNode::class);
		if (
			!$group instanceof AttributeGroupNode
			|| !($class instanceof ClassNode || $class instanceof AnonymousClassNode || $class instanceof EnumNode)
			|| !self::declaresReturnType($node, $class, $resolver)
			|| $group->hasInnerComment()
			|| !$context->report($group, 'Useless `#[\ReturnTypeWillChange]`, because the method declares its return type.')
		) {
			return;
		}

		$group->remove();
	}


	/** Whether the method declares the return type of the method of an interface of PHP the class implements. */
	private static function declaresReturnType(
		MethodNode $method,
		ClassNode|AnonymousClassNode|EnumNode $class,
		NameResolver $resolver,
	): bool
	{
		$name = strtolower($method->name->text);
		$type = $method->returnType;
		$declared = match (true) {
			!$type instanceof NamedTypeNode => null,
			$type->isBuiltin() => $type->name->text,
			default => $resolver->resolveClass($type->name),
		};
		foreach ($class->implements?->getItems() ?? [] as $interface) {
			$expected = self::ReturnTypes[$resolver->resolveClass($interface)][$name] ?? null;
			if ($expected === 'mixed' || ($expected !== null && $declared !== null && strcasecmp($declared, $expected) === 0)) {
				return true;
			}
		}

		return false;
	}
}
