<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{PhpSignatures, Types};
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token, Visibility};
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentNode, ArrayItemNode, Expression, FunctionLikeNode, IdentifierNode, ListNode, ModifiersNode, NameNode, ParameterNode, SeparatedNodeList, Statement};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode, PropertyNode};
use PhpSyntax\Nodes\Statement\ClassNode;
use function count;


/**
 * A property the constructor sets and nothing else touches is `readonly`, which says so and makes PHP keep
 * it. The property must carry a type and no default value, which is what readonly takes, and the constructor
 * itself must assign it through `$this` exactly once and outside a loop: a closure inside the constructor is not it,
 * a write to a clone is what readonly refuses, and a second assignment fails. A promoted parameter is read the same
 * way, promotion being the assignment it needs. Nothing may change the value in place either: a combined
 * assignment, an element written, a reference taken, a parameter taking it by reference. Where the code does not
 * show whether a call takes it by reference, the fix is risky.
 *
 * Who else may write the property the code shows by its visibility: a private one nothing outside the class
 * reaches, whatever the class is, and any other one only where the class is final. A property that is not
 * private is a risky subject in a final class, the code that writes it from outside not being in the file; one of
 * a class that can be extended is left alone altogether, because a child redeclaring it would then be
 * a fatal error rather than a changed behaviour. A hooked property cannot be readonly at all.
 */
#[RuleInfo(
	'dresscode/readonlyForUnwrittenProperty',
	Stage::Structure,
	description: 'Marks a property written only in the constructor as readonly',
	requires: ['php' => '>=8.1'],
)]
final class ReadonlyForUnwrittenPropertyRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| $node->modifiers->isReadonly()
		) {
			return;
		}

		$constructor = null;
		foreach ($node->members as $member) {
			if ($member instanceof MethodNode && $member->isConstructor()) {
				$constructor = $member;
			}
		}

		if ($constructor === null) {
			return;
		}

		$final = $node instanceof AnonymousClassNode || $node->modifiers->isFinal();
		foreach ($node->members as $member) {
			if ($member instanceof PropertyNode && count($member->items) === 1) {
				$item = $member->items->getItems()[0];
				$this->consider($member, $member->modifiers, $item->plainName, $item->default !== null, $node, $constructor, $final, $context);
			}
		}

		foreach ($constructor->parameters->getItems() as $parameter) {
			if ($parameter->isPromoted() && $parameter->variable->plainName !== null) {
				$this->consider($parameter, $parameter->modifiers, $parameter->variable->plainName, false, $node, $constructor, $final, $context);
			}
		}
	}


	/** Marks the member readonly where nothing but the constructor writes it. */
	private function consider(
		PropertyNode|ParameterNode $member,
		ModifiersNode $modifiers,
		string $name,
		bool $hasDefault,
		ClassNode|AnonymousClassNode $class,
		MethodNode $constructor,
		bool $final,
		RuleContext $context,
	): void
	{
		if (
			$modifiers->isReadonly()
			|| $modifiers->isStatic()
			|| $hasDefault
			|| $member->type === null
			|| $member->hooks !== null
			|| (!($modifiers->visibility === Visibility::Private) && !$final)
		) {
			return;
		}

		$assignments = 0;
		$uncertain = false;
		foreach ($class->find(Expression\PropertyFetchNode::class) as $fetch) {
			$named = $fetch->name instanceof IdentifierNode;
			if ($named && $fetch->name->text !== $name) {
				continue;
			} elseif ($named && self::isInitialization($fetch)) {
				if (!$fetch->isOfThis() || $fetch->isNullsafe() || !self::runsOnce($fetch, $constructor)) {
					return;
				}
				$assignments++;
				continue;
			}

			$modified = self::isModified($fetch, $class, $context);
			if ($modified === Tristate::Yes || ($modified === Tristate::Maybe && !$named)) { // an expression may name this property
				return;
			}
			$uncertain = $uncertain || $modified === Tristate::Maybe;
		}

		if ($assignments !== ($member instanceof ParameterNode ? 0 : 1)) {
			return;
		}

		$risk = match (true) {
			$modifiers->visibility !== Visibility::Private => [Risk::BehaviorChanges, 'code outside the file may write it'],
			$uncertain => [Risk::TypeUnknown, 'a call may take it by reference'],
			default => [null, null],
		};
		if (!$context->report($member, 'The property nothing but the constructor writes must be readonly', risk: $risk[0], because: $risk[1])) {
			return;
		}

		if ($var = $modifiers->findToken(Token::Var)) { // readonly does not go with var
			$modifiers->removeToken($var);
			$modifiers->append(new Token(Token::Public, 'public'));
		}

		$modifiers->append(new Token(Token::Readonly, 'readonly'));
	}


	/** Whether the property is the target of a plain assignment, alone or as an item of a destructuring one. */
	private static function isInitialization(Expression\PropertyFetchNode $fetch): bool
	{
		$node = $fetch;
		$parent = $node->parent;
		while (
			($parent instanceof ArrayItemNode && $parent->value === $node && $parent->ampersand === null)
			|| $parent instanceof SeparatedNodeList
			|| $parent instanceof ListNode
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
			|| $parent instanceof ListNode
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
				|| (($parent->target instanceof ListNode || $parent->target instanceof Expression\ArrayNode)
					&& $parent->target->find(ArrayItemNode::class, fn(ArrayItemNode $item) => $item->ampersand !== null) !== []),
			$parent instanceof Expression\CombinedAssignmentNode => $parent->target === $node,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode,
			$parent instanceof Statement\UnsetNode => true,
			$parent instanceof Statement\ForeachNode => $parent->expression === $node ? $parent->ampersand !== null && !$literal : true,
			$literal => false,
			$parent instanceof Statement\ReturnNode => self::returnsByReference($parent->findAncestor(FunctionLikeNode::class)),
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


	private static function returnsByReference(?FunctionLikeNode $function): bool
	{
		return ($function instanceof MethodNode
			|| $function instanceof Statement\FunctionNode
			|| $function instanceof Expression\ClosureNode
			|| $function instanceof Expression\ArrowFunctionNode
			|| $function instanceof PropertyHookNode)
			&& $function->ampersand !== null;
	}


	/**
	 * Whether the parameter the argument binds to takes it by reference, as the declaration in the file, the one of
	 * PHP or the types say; null where nothing tells.
	 */
	private static function takesByReference(ArgumentNode $argument, ClassNode|AnonymousClassNode $class, RuleContext $context): ?bool
	{
		$list = $argument->parent;
		$call = $list?->parent?->parent;
		$parameters = match (true) {
			$call instanceof Expression\FunctionCallNode => self::findFunctionParameters($call, $context),
			$call instanceof Expression\MethodCallNode,
			$call instanceof Expression\StaticMethodCallNode,
			$call instanceof Expression\NewNode => self::findMethodParameters($call, $class, $context),
			default => null,
		};
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

		foreach ($parameters as $index => [$name, $byReference]) {
			if ($argument->name === null ? $index === $position : $argument->name->text === $name) {
				return $byReference;
			}
		}

		$last = $parameters[count($parameters) - 1] ?? null;
		return $last !== null && $last[1] && $last[2]; // the rest goes to the variadic parameter, or nowhere
	}


	/**
	 * The parameters of the function the call calls, each as its name, whether it takes the argument by reference and
	 * whether it is variadic.
	 * @return ?list<array{string, bool, bool}>
	 */
	private static function findFunctionParameters(Expression\FunctionCallNode $call, RuleContext $context): ?array
	{
		if (!$call->name instanceof NameNode) {
			return null;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = $resolver->resolveFunction($call->name);
		$declaration = $resolver->findDeclaration($function, SymbolKind::Function);
		if ($declaration instanceof Statement\FunctionNode) {
			return self::describeParameters($declaration->parameters->getItems());
		}

		$parameters = $resolver->isGlobalFunctionCall($call)
			? $context->getAnalysis(PhpSignatures::class)->findParameters($function)
			: null;
		return $parameters === null
			? null
			: array_map(fn($parameter) => [$parameter->name, $parameter->byReference, $parameter->variadic], $parameters);
	}


	/**
	 * The parameters of the method the call or instantiation runs, as the class in sight declares them for a call on
	 * `$this`, `self` or `static`, and as the types say otherwise.
	 * @return ?list<array{string, bool, bool}>
	 */
	private static function findMethodParameters(
		Expression\MethodCallNode|Expression\StaticMethodCallNode|Expression\NewNode $call,
		ClassNode|AnonymousClassNode $class,
		RuleContext $context,
	): ?array
	{
		$own = match (true) {
			$call instanceof Expression\MethodCallNode => $call->object instanceof Expression\VariableNode && $call->object->isThis(),
			$call instanceof Expression\StaticMethodCallNode => $call->class instanceof NameNode && ($call->class->equals('self') || $call->class->equals('static')),
			default => false,
		};
		if ($own && !$call instanceof Expression\NewNode && $call->name instanceof IdentifierNode) {
			foreach ($class->members as $member) {
				if ($member instanceof MethodNode && $member->name->equals($call->name->text)) {
					return self::describeParameters($member->parameters->getItems());
				}
			}
		}

		$types = $context->findAnalysis(Types::class);
		$access = $types?->findMemberAccess($call);
		$parameters = $access === null ? null : $types->findParameters($access);
		return $parameters === null
			? null
			: array_map(fn($parameter) => [$parameter->name, $parameter->byReference, $parameter->variadic], $parameters);
	}


	/**
	 * @param list<ParameterNode> $parameters
	 * @return list<array{string, bool, bool}>
	 */
	private static function describeParameters(array $parameters): array
	{
		return array_map(
			fn(ParameterNode $parameter) => [(string) $parameter->variable->plainName, $parameter->ampersand !== null, $parameter->ellipsis !== null],
			$parameters,
		);
	}
}
