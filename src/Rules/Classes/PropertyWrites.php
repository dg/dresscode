<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Parameter;
use DressCode\{RuleContext, Tristate};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentNode, ArrayItemNode, DestructuringNode, Expression, FunctionLikeNode, IdentifierNode, NameNode, ParameterNode, SeparatedNodeList, Statement};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use function count;


/**
 * How a class writes one of its properties: the plain assignments to it through `$this` that the constructor itself
 * runs once, outside a loop and a closure, and whether a call the code does not show may take it by reference.
 * @internal
 */
final readonly class PropertyWrites
{
	public function __construct(
		/** the plain assignments through `$this` the constructor runs once */
		public int $initializations,
		/** whether a call whose parameters nothing tells may take the property by reference */
		public bool $uncertain,
	) {
	}


	/**
	 * The writes of the property of the name in the class; null where anything else writes it: an assignment
	 * elsewhere or to another object, a combined assignment, an element written, a reference taken, a parameter
	 * taking it by reference, an expression that may name it.
	 */
	public static function fromClass(
		ClassNode|AnonymousClassNode $class,
		string $name,
		?MethodNode $constructor,
		RuleContext $context,
	): ?self
	{
		$initializations = 0;
		$uncertain = false;
		foreach ($class->find(Expression\PropertyFetchNode::class) as $fetch) {
			$named = $fetch->name instanceof IdentifierNode;
			if ($named && $fetch->name->text !== $name) {
				continue;
			} elseif ($named && self::isInitialization($fetch)) {
				if ($constructor === null || !$fetch->isOfThis() || $fetch->nullsafe || !self::runsOnce($fetch, $constructor)) {
					return null;
				}
				$initializations++;
				continue;
			}

			$modified = self::isModified($fetch, $class, $context);
			if ($modified === Tristate::Yes || ($modified === Tristate::Maybe && !$named)) { // an expression may name this property
				return null;
			}
			$uncertain = $uncertain || $modified === Tristate::Maybe;
		}

		return new self($initializations, $uncertain);
	}


	/** Whether the property is the target of a plain assignment, alone or as an item of a destructuring one. */
	private static function isInitialization(Expression\PropertyFetchNode $fetch): bool
	{
		$node = $fetch;
		$parent = $node->parent;
		while (
			($parent instanceof ArrayItemNode && $parent->value === $node && $parent->ampersand === null)
			|| $parent instanceof SeparatedNodeList
			|| $parent instanceof DestructuringNode
			|| $parent instanceof Expression\ArrayNode
		) {
			[$node, $parent] = [$parent, $parent->parent];
		}

		return $parent instanceof Expression\AssignmentNode && $parent->target === $node;
	}


	/** Whether the assignment stands in the constructor itself and outside a loop, so that it runs at most once. */
	private static function runsOnce(Expression\PropertyFetchNode $fetch, MethodNode $constructor): bool
	{
		for ($node = $fetch->parent; $node !== $constructor; $node = $node->parent) {
			if (
				$node === null
				|| $node instanceof FunctionLikeNode
				|| $node instanceof Statement\ForNode
				|| $node instanceof Statement\ForeachNode
				|| $node instanceof Statement\WhileNode
				|| $node instanceof Statement\DoWhileNode
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Whether something other than a plain assignment changes the property: it is stepped, unset, written by
	 * a combined assignment or as an element, iterated into, or bound to a reference, which a call taking it by
	 * reference does as well; Maybe where the parameter of the call is unknown.
	 */
	private static function isModified(
		Expression\PropertyFetchNode $fetch,
		ClassNode|AnonymousClassNode $class,
		RuleContext $context,
	): Tristate
	{
		$node = $fetch;
		$parent = $node->parent;
		$literal = false; // an array literal is a new value, which only destructuring writes through
		while (
			($parent instanceof Expression\ArrayAccessNode && $parent->expression === $node)
			|| ($parent instanceof Expression\PropertyFetchNode && $parent->object === $node)
			|| ($parent instanceof ArrayItemNode && $parent->value === $node)
			|| $parent instanceof DestructuringNode
			|| $parent instanceof Expression\ArrayNode
			|| $parent instanceof SeparatedNodeList
		) {
			if ($parent instanceof ArrayItemNode && $parent->ampersand !== null) {
				return Tristate::Yes;
			}
			$literal = $literal || $parent instanceof ArrayItemNode;
			[$node, $parent] = [$parent, $parent->parent];
		}

		$byReference = match (true) {
			$parent instanceof Expression\AssignmentNode => $parent->target === $node
				|| (($parent->target instanceof DestructuringNode || $parent->target instanceof Expression\ArrayNode)
					&& $parent->target->find(ArrayItemNode::class, fn(ArrayItemNode $item) => $item->ampersand !== null) !== []),
			$parent instanceof Expression\CombinedAssignmentNode => $parent->target === $node,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode,
			$parent instanceof Statement\UnsetNode => true,
			$parent instanceof Statement\ForeachNode => $parent->expression === $node ? $parent->ampersand !== null && !$literal : true,
			$literal => false,
			$parent instanceof Statement\ReturnNode => $parent->findAncestor(FunctionLikeNode::class)?->ampersand !== null,
			$parent instanceof Expression\YieldNode => $parent->value === $node && $parent->findAncestor(FunctionLikeNode::class)?->ampersand !== null,
			$parent instanceof Expression\ArrowFunctionNode => $parent->ampersand !== null,
			$parent instanceof ArgumentNode => $parent->ampersand !== null ? true : self::takesByReference($parent, $class, $context),
			default => false,
		};

		return match ($byReference) {
			true => Tristate::Yes,
			false => Tristate::No,
			null => Tristate::Maybe,
		};
	}


	/**
	 * Whether the parameter the argument binds to takes it by reference, as the declaration in the file, the one of
	 * PHP or the types say; null where nothing tells.
	 */
	private static function takesByReference(ArgumentNode $argument, ClassNode|AnonymousClassNode $class, RuleContext $context): ?bool
	{
		$list = $argument->parent;
		$call = $list?->parent?->parent;
		$parameters = $call instanceof Expression\FunctionCallNode
			|| $call instanceof Expression\MethodCallNode
			|| $call instanceof Expression\StaticMethodCallNode
			|| $call instanceof Expression\NewNode
				? self::findOwnParameters($call, $class, $context) ?? NodeHelpers::findParameters($call, $context)
				: null;
		if ($parameters === null || !$list instanceof SeparatedNodeList) {
			return null;
		}

		$position = 0;
		foreach ($list->getItems() as $item) {
			if ($item === $argument) {
				break;
			} elseif (!$item instanceof ArgumentNode || $item->ellipsis !== null) {
				return null;
			}
			$position++;
		}

		foreach ($parameters as $index => $parameter) {
			if ($argument->name === null ? $index === $position : $argument->name->text === $parameter->name) {
				return $parameter->byReference;
			}
		}

		$last = $parameters[count($parameters) - 1] ?? null;
		return $last !== null && $last->byReference && $last->variadic; // the rest goes to the variadic parameter, or nowhere
	}


	/**
	 * The parameters of the method a call on `$this`, `self` or `static` runs, as the class in sight declares them,
	 * which needs no types; null for any other call.
	 * @return ?list<Parameter>
	 */
	private static function findOwnParameters(
		Expression\FunctionCallNode|Expression\MethodCallNode|Expression\StaticMethodCallNode|Expression\NewNode $call,
		ClassNode|AnonymousClassNode $class,
		RuleContext $context,
	): ?array
	{
		$own = match (true) {
			$call instanceof Expression\MethodCallNode => $call->isOfThis(),
			$call instanceof Expression\StaticMethodCallNode => $call->class instanceof NameNode && ($call->class->equals('self') || $call->class->equals('static')),
			default => false,
		};
		if (!$own || !$call->name instanceof IdentifierNode) {
			return null;
		}

		foreach ($class->members as $member) {
			if ($member instanceof MethodNode && $member->name->equals($call->name->text)) {
				$resolver = $context->getAnalysis(NameResolver::class);
				return array_map(fn(ParameterNode $parameter) => NodeHelpers::toParameter($parameter, $resolver), $member->parameters->getItems());
			}
		}

		return null;
	}
}
