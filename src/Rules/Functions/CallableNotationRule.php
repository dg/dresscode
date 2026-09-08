<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\{Parameter, PhpSignatures, Types};
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate, Violation};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, DereferenceKind, Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, ClassLikeNode, Expression, ExpressionNode, IdentifierNode, NameNode, ParameterNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use function count;


/**
 * A callable written as the first-class callable of PHP 8.1 or the partial application of PHP 8.6.
 * `Closure::fromCallable('trim')` is `trim(...)`, `Closure::fromCallable([$obj, 'run'])` is `$obj->run(...)`. A
 * closure or an arrow function whose sole parameter is variadic and unpacked into one call,
 * `fn(...$args) => max(...$args)`, is `max(...)`. From PHP 8.6 on, a closure that passes its own parameters on to one
 * call in the same order is a partial application of that call: `fn($s) => strlen($s)` is `strlen(?)`, and one
 * passing arguments of its own next to them, `fn($x) => foo(1, $x)`, is `foo(1, ?)`.
 *
 * A closure naming its parameters one by one is never written as a first-class callable, and below PHP 8.6 it stays,
 * because the closure declares how many arguments it takes and the callable behind it declares its own: a consumer
 * passing more than the closure spells out gets them ignored by the closure and passed on by the callable, which a
 * builtin function answers with an error and a function with an optional parameter with another behaviour. A partial
 * application ignores them as the closure does, and a sole variadic parameter unpacked into the call passes on
 * whatever it is given, as the callable does.
 *
 * A first-class callable evaluates the object of a method once, when it is made, and a partial application its
 * arguments too; the closure evaluates them at every call. A literal and a constant give the same either way, and so
 * does a variable, which an arrow function captures when it is made too, while a closure without `use` does not have
 * it at all and stays. Any other read, a property or an element, may give another value later, so the fix is risky;
 * anything else, a call above all, would run once instead of every time, and the closure stays. The parameters of the
 * closure must not declare anything a placeholder would not, a type, a default, a reference: a placeholder takes the
 * parameter of the function as it is declared, required and typed. A partial application refuses, when it is made, a
 * call given fewer arguments than the function requires, an error the closure raised only when called. A function
 * reading the scope of its caller, `compact()` among them, keeps the closure. A parameter taking its argument by
 * reference makes a partial application risky, the partial passing on what the closure passed a copy of, and so does
 * a function or a method whose parameters neither the file, PHP nor the types tell, which makes a first-class callable
 * risky too; a function known to take one is not made a first-class callable of. A closure carrying an attribute
 * stays, a callable having nowhere to keep it.
 *
 * A callable naming a class, `[Foo::class, 'make']` or `'Foo::make'`, resolves `static` in the method to the class of
 * `$this` where `$this` is a `Foo`, and `[self::class, 'make']` to `self` where `self::make(...)` passes a static
 * caller on, so inside a class the fix is risky; `static::class` names the same class either way. A variable or a
 * property in the place of the object may hold the name of a class as well, which `->` calls no method on, so the fix
 * is risky unless it is `$this` or the types say it is an object, and the callable stays where they say it is not.
 *
 * A bare array or string callable stays as it is: the code does not say that `[$obj, 'run']` is a callable at all.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=8.1'],
	decisions: ['upgrading.syntax.firstClassCallables'],
	analyses: [PhpSignatures::class, Types::class, NameResolver::class],
)]
final class CallableNotationRule extends NodeRule
{
	private const PartialApplicationVersion = '8.6';

	/** functions that read or write the scope they are called in, which a callable does not have */
	private const ScopeFunctions = ['compact', 'extract', 'func_get_arg', 'func_get_args', 'func_num_args', 'get_defined_vars'];


	public function getVisitedNodes(): array
	{
		return [Expression\ClosureNode::class, Expression\ArrowFunctionNode::class, Expression\StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof Expression\StaticMethodCallNode) {
			[$callable, $risk, $because] = self::readFromCallable($node, $context) ?? [null, null, null];
			if ($callable !== null) {
				self::writeFirstClass($node, $callable, $context, $risk, $because);
			}

		} elseif ($node instanceof Expression\ClosureNode || $node instanceof Expression\ArrowFunctionNode) {
			if (
				self::forwardsVariadic($node)
				&& ($call = ForwardingClosure::findCall($node)) !== null
				&& ($callable = self::readForwarding($node, $call)) !== null
				&& ($reference = self::takesReference($call, $context)) !== Tristate::Yes
				&& ($risky = self::classifyCallee($call, $node, $context)) !== null
			) {
				[$risk, $because] = match (true) {
					$risky => [Risk::BehaviorChanges, 'the object is evaluated when the callable is made, not when it is called'],
					$reference === Tristate::Maybe => [Risk::TypeUnknown, 'the call may take an argument by reference, where the closure passed a copy'],
					default => [null, null],
				};
				self::writeFirstClass($node, $callable, $context, $risk, $because);
			} elseif (version_compare($context->phpVersion, self::PartialApplicationVersion, '>=')) {
				self::writePartial($node, $context);
			}
		}
	}


	/** Writes the node as the first-class callable, with the risk of the fix and its reason. */
	private static function writeFirstClass(Node $node, string $callable, RuleContext $context, ?Risk $risk, ?string $because): void
	{
		if (
			$node->hasInnerComment()
			|| !$context->report(
				$node,
				'The callable must be written ' . Violation::formatCode("$callable(...)") . '.',
				risk: $risk,
				because: $because,
			)
		) {
			return;
		}

		$node->replaceWith((new Builder)->expression("$callable(...)"));
	}


	/**
	 * What `Closure::fromCallable()` makes a closure of, written the way a first-class callable names it, with the risk
	 * of writing it so and its reason.
	 * @return array{string, ?Risk, ?string}|null
	 */
	private static function readFromCallable(Expression\StaticMethodCallNode $call, RuleContext $context): ?array
	{
		$arguments = $call->arguments->items->getItems();
		$argument = $arguments[0] ?? null;
		if (
			!$call->class instanceof NameNode
			|| !$call->name instanceof IdentifierNode
			|| !$call->name->equals('fromCallable')
			|| count($arguments) !== 1
			|| !$argument instanceof ArgumentNode
			|| $argument->name !== null || $argument->ampersand !== null || $argument->ellipsis !== null
			|| $context->getAnalysis(NameResolver::class)->resolveClass($call->class) !== 'Closure'
		) {
			return null;
		}

		$value = $argument->value;
		$static = $call->findAncestor(ClassLikeNode::class) === null
			? [null, null]
			: [Risk::BehaviorChanges, 'the class `static` resolves to in the method may differ'];
		if ($value instanceof StringNode) {
			// the string names a function or a method of the global namespace, whatever namespace it stands in
			return preg_match('~^\\\\?\w+(\\\\\w+)*(::\w+)?$~D', $value->toValue()) === 1
				&& !ForwardingClosure::isScopeRelative(explode('::', $value->toValue())[0])
					? ['\\' . ltrim($value->toValue(), '\\'), ...(str_contains($value->toValue(), '::') ? $static : [null, null])]
					: null;
		}

		$items = $value instanceof Expression\ArrayNode ? $value->items->getItems() : [];
		[$first, $second] = [$items[0] ?? null, $items[1] ?? null];
		if (
			count($items) !== 2
			|| !$first instanceof ArrayItemNode || !self::isPlainItem($first)
			|| !$second instanceof ArrayItemNode || !self::isPlainItem($second)
		) {
			return null;
		}

		[$target, $method] = [$first->value, $second->value];
		if (
			!$target instanceof ExpressionNode
			|| !$method instanceof StringNode
			|| preg_match('~^\w+$~D', $method->toValue()) !== 1
		) {
			return null;
		}

		return match (true) {
			$target instanceof StringNode => preg_match('~^\\\\?\w+(\\\\\w+)*$~D', $target->toValue()) === 1
				&& !ForwardingClosure::isScopeRelative($target->toValue())
					? ['\\' . ltrim($target->toValue(), '\\') . '::' . $method->toValue(), ...$static]
					: null,
			self::namesClass($target) => [
				$target->class->text . '::' . $method->toValue(),
				...(strtolower($target->class->text) === 'static' ? [null, null] : $static),
			],
			$target->isDereferenceable(DereferenceKind::Fetch)
			&& !$target->isInNullsafeChain()
			&& ($object = self::isObject($target, $context)) !== Tristate::No => [
				$target->text . '->' . $method->toValue(),
				...($object === Tristate::Maybe ? [Risk::TypeUnknown, 'the value may be the name of a class, not an object'] : [null, null]),
			],
			default => null,
		};
	}


	/** Whether the value is an object rather than the name of a class, which a callable may hold as well. */
	private static function isObject(ExpressionNode $value, RuleContext $context): Tristate
	{
		return $value instanceof Expression\VariableNode && $value->isThis()
			? Tristate::Yes
			: $context->findAnalysis(Types::class)?->isOfType($value, 'object') ?? Tristate::Maybe;
	}


	/** The callee of the call, where the function does nothing but pass its own parameters on to it, in their order. */
	private static function readForwarding(
		Expression\ClosureNode|Expression\ArrowFunctionNode $function,
		Expression\FunctionCallNode|Expression\MethodCallNode|Expression\StaticMethodCallNode $call,
	): ?string
	{
		$callee = ForwardingClosure::writeCallee($call);
		$parameters = $function->parameters->getItems();
		$arguments = $call->arguments->items->getItems();
		return $callee !== null
			&& count($parameters) === count($arguments)
			&& array_all($parameters, fn(ParameterNode $parameter, int $i) => ForwardingClosure::passesParameter($parameter, $arguments[$i]))
				? $callee
				: null;
	}


	/** Whether the function takes one variadic parameter and unpacks it into the call, which keeps the arity. */
	private static function forwardsVariadic(Expression\ClosureNode|Expression\ArrowFunctionNode $function): bool
	{
		$parameters = $function->parameters->getItems();
		return count($parameters) === 1
			&& $parameters[0]->ellipsis !== null
			&& $parameters[0]->type === null;
	}


	/** Writes the closure as a partial application where it may be one. */
	private static function writePartial(Expression\ClosureNode|Expression\ArrowFunctionNode $node, RuleContext $context): void
	{
		if (
			($call = ForwardingClosure::findCall($node)) === null
			|| ($callee = ForwardingClosure::writeCallee($call)) === null
			|| $node->parameters->isEmpty()
			|| ($risky = self::classifyCallee($call, $node, $context)) === null
		) {
			return;
		}

		$parameters = $node->parameters->getItems();
		$next = 0;
		$parts = [];
		foreach ($call->arguments->items->getItems() as $argument) {
			$parameter = $parameters[$next] ?? null;
			if ($parameter !== null && ForwardingClosure::passesParameter($parameter, $argument)) {
				$parts[] = $parameter->ellipsis === null ? '?' : '...';
				$next++;
				continue;
			}

			$fixed = $argument instanceof ArgumentNode && $argument->ellipsis === null && $argument->ampersand === null
				? self::classifyEvaluation($argument->value, $node)
				: null;
			if ($fixed === null) {
				return;
			}
			$risky = $risky || $fixed;
			$parts[] = $argument->text;
		}

		if ($next !== count($parameters)) {
			return;
		}

		$reference = self::takesReference($call, $context);
		if (
			!$node->hasInnerComment()
			&& $context->report(
				$node,
				'The closure forwarding its parameters to one call must be written as a partial application.',
				risk: match (true) {
					$reference === Tristate::Yes || $risky => Risk::BehaviorChanges,
					$reference === Tristate::Maybe => Risk::TypeUnknown,
					default => null,
				},
				because: match (true) {
					$reference === Tristate::Yes => 'the call takes an argument by reference, where the closure passed a copy',
					$risky => 'the arguments are evaluated when the partial is made, not when it is called',
					$reference === Tristate::Maybe => 'the call may take an argument by reference, where the closure passed a copy',
					default => null,
				},
			)
		) {
			$node->replaceWith((new Builder)->expression($callee . '(' . implode(', ', $parts) . ')'));
		}
	}


	/**
	 * What the call calls, classified as `classifyEvaluation()` classifies an expression: the object of a method by
	 * it, and a function reading the scope of its caller as null.
	 */
	private static function classifyCallee(
		Expression\FunctionCallNode|Expression\MethodCallNode|Expression\StaticMethodCallNode $call,
		Expression\ClosureNode|Expression\ArrowFunctionNode $function,
		RuleContext $context,
	): ?bool
	{
		return match (true) {
			$call instanceof Expression\MethodCallNode => self::classifyEvaluation($call->object, $function),
			$call instanceof Expression\FunctionCallNode => self::readsScope($call, $context) ? null : false,
			default => false,
		};
	}


	/**
	 * Whether the expression gives another value when the callable is made than at a call of the function: false
	 * where it gives the same, true where it may differ, null where it must not move at all, because it runs code,
	 * reads a parameter of the function or a variable a closure does not have.
	 */
	private static function classifyEvaluation(
		ExpressionNode $expression,
		Expression\ClosureNode|Expression\ArrowFunctionNode $function,
	): ?bool
	{
		$parameters = array_map(fn(ParameterNode $parameter) => $parameter->variable->plainName, $function->parameters->getItems());
		foreach ([$expression, ...$expression->find(Expression\VariableNode::class)] as $variable) {
			if (
				$variable instanceof Expression\VariableNode
				&& !$variable->isThis()
				&& ($function instanceof Expression\ClosureNode || in_array($variable->plainName, $parameters, true))
			) {
				return null;
			}
		}

		return match (true) {
			$expression->hasValue(),
			$expression instanceof Expression\VariableNode,
			$expression->isConstantRead() => false,
			$expression->isRepeatableRead() => true,
			default => null,
		};
	}


	/** Whether the call calls a function of PHP that reads the scope of its caller, which a partial application does not have. */
	private static function readsScope(Expression\FunctionCallNode $call, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $call->name instanceof NameNode
			&& $resolver->isGlobalFunctionCall($call)
			&& in_array(strtolower(ltrim($resolver->resolveFunction($call->name), '\\')), self::ScopeFunctions, true);
	}


	/**
	 * Whether the function or the method the call calls takes an argument by reference, which the callable passes on
	 * where the closure passed a copy, as the declaration in the file, the signature of PHP or, of a method, the
	 * types say; maybe where none of them tells.
	 */
	private static function takesReference(
		Expression\FunctionCallNode|Expression\MethodCallNode|Expression\StaticMethodCallNode $call,
		RuleContext $context,
	): Tristate
	{
		$parameters = null;
		if ($call instanceof Expression\FunctionCallNode && $call->name instanceof NameNode) {
			$resolver = $context->getAnalysis(NameResolver::class);
			$function = $resolver->resolveFunction($call->name);
			$declaration = $resolver->findDeclaration($function, SymbolKind::Function);
			if ($declaration instanceof FunctionNode) {
				return array_any($declaration->parameters->getItems(), fn(ParameterNode $parameter) => $parameter->ampersand !== null)
					? Tristate::Yes
					: Tristate::No;
			} elseif ($resolver->isGlobalFunctionCall($call)) {
				$parameters = $context->getAnalysis(PhpSignatures::class)->findParameters(strtolower(ltrim($function, '\\')));
			}

		} elseif (!$call instanceof Expression\FunctionCallNode) {
			$types = $context->findAnalysis(Types::class);
			$access = $types?->findMemberAccess($call);
			$parameters = $access === null ? null : $types->findParameters($access);
		}

		return match (true) {
			$parameters === null => Tristate::Maybe,
			array_any($parameters, fn(Parameter $parameter) => $parameter->byReference) => Tristate::Yes,
			default => Tristate::No,
		};
	}


	/**
	 * Whether the expression names a class rather than an object, which only `X::class` says of itself.
	 * @phpstan-assert-if-true Expression\ClassConstantFetchNode $expression
	 */
	private static function namesClass(ExpressionNode $expression): bool
	{
		return $expression instanceof Expression\ClassConstantFetchNode
			&& $expression->name instanceof IdentifierNode
			&& $expression->name->equals('class');
	}


	/** Whether the item of an array is a plain value, neither keyed nor spread nor taken by reference. */
	private static function isPlainItem(Node $item): bool
	{
		return $item instanceof ArrayItemNode
			&& $item->key === null
			&& $item->ampersand === null
			&& $item->ellipsis === null;
	}
}
