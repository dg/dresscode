<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{AccessKind, Node, Parser, Token};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, Expression, ExpressionNode, IdentifierNode, NameNode, ParameterNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use function count, in_array;


/**
 * A callable written as the first-class callable of PHP 8.1: `Closure::fromCallable('trim')` is `trim(...)`,
 * `Closure::fromCallable([$obj, 'run'])` is `$obj->run(...)`, and a closure or an arrow function that passes
 * its own parameters on to one call in the same order is the callable of that call, `fn($s) => strlen($s)`
 * being `strlen(...)`.
 *
 * The closure is a risky subject, because it declares how many arguments it takes and the callable behind it
 * declares its own: a consumer passing more than the closure spells out gets them ignored today and passed on
 * after the fix, which an internal function answers with an error. Only a closure whose sole parameter is
 * variadic and unpacked into the call says the arity is the same. A bare array or string callable stays as it
 * is: the code does not say that `[$obj, 'run']` is a callable at all.
 */
#[RuleInfo(
	'dresscode/first-class-callable-notation',
	Stage::Structure,
	description: 'Writes a callable in the first-class callable notation',
	group: Group::Modernization,
	requires: ['php' => '>=8.1'],
)]
final class FirstClassCallableNotationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\ClosureNode::class, Expression\ArrowFunctionNode::class, Expression\StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionNode || $node->hasComment()) {
			return;
		}

		[$callable, $risky] = $node instanceof Expression\StaticMethodCallNode
			? [$this->readFromCallable($node, $context), false]
			: [$this->readForwarding($node), !self::forwardsVariadic($node)];
		if (
			$callable === null
			|| !$context->report($node, "The callable must be written $callable(...)", risky: $risky)
		) {
			return;
		}

		$node->replaceWith((new Parser)->parseExpression("$callable(...)"));
	}


	/** What `Closure::fromCallable()` makes a closure of, written the way a first-class callable names it. */
	private function readFromCallable(Expression\StaticMethodCallNode $call, RuleContext $context): ?string
	{
		$arguments = $call->arguments->items->getItems();
		$argument = $arguments[0] ?? null;
		if (
			!$call->class instanceof NameNode
			|| $context->getAnalysis(NameResolver::class)->resolveClass($call->class) !== 'Closure'
			|| !$call->name instanceof IdentifierNode
			|| strcasecmp($call->name->text, 'fromCallable') !== 0
			|| count($arguments) !== 1
			|| !$argument instanceof ArgumentNode
			|| $argument->name || $argument->ampersand || $argument->ellipsis
		) {
			return null;
		}

		$value = $argument->value;
		if ($value instanceof StringNode) {
			// the string names a function or a method of the global namespace, whatever namespace it stands in
			return preg_match('~^\\\\?\w+(\\\\\w+)*(::\w+)?$~D', $value->value) === 1
				&& !self::isScopeRelative(explode('::', $value->value)[0])
				? '\\' . ltrim($value->value, '\\')
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
			|| preg_match('~^\w+$~D', $method->value) !== 1
		) {
			return null;
		}

		return match (true) {
			$target instanceof StringNode => preg_match('~^\\\\?\w+(\\\\\w+)*$~D', $target->value) === 1
				&& !self::isScopeRelative($target->value)
				? '\\' . ltrim($target->value, '\\') . '::' . $method->value
				: null,
			self::namesClass($target) => $target->class->text . '::' . $method->value,
			$target->isDereferenceable(AccessKind::Member) => $target->text . '->' . $method->value,
			default => null,
		};
	}


	/** The call a closure or an arrow function does nothing but pass its own parameters on to, in their order. */
	private function readForwarding(ExpressionNode $function): ?string
	{
		assert($function instanceof Expression\ClosureNode || $function instanceof Expression\ArrowFunctionNode);
		$body = $function instanceof Expression\ArrowFunctionNode
			? $function->expression
			: self::readSingleReturn($function);
		if (
			!$body instanceof Expression\FunctionCallNode
			&& !$body instanceof Expression\MethodCallNode
			&& !$body instanceof Expression\StaticMethodCallNode
		) {
			return null;
		}

		$callee = match (true) {
			$body instanceof Expression\FunctionCallNode => $body->name instanceof NameNode ? $body->name->text : null,
			$body instanceof Expression\MethodCallNode => $body->name instanceof IdentifierNode && $body->object->isDereferenceable(AccessKind::Member)
				? $body->object->text . $body->operator->text . $body->name->text
				: null,
			// self, parent and static are resolved where the callable is made, not where it is called, and
			// parent in a class without one is an error the closure would only ever have raised when called
			default => $body->name instanceof IdentifierNode
				&& $body->class instanceof NameNode
				&& !self::isScopeRelative($body->class->text)
				? $body->class->text . '::' . $body->name->text
				: null,
		};
		return $callee !== null
			&& $function->ampersand === null
			&& $function->returnType === null
			&& (!$function instanceof Expression\ClosureNode || $function->uses === null)
			&& self::passesParameters($function->parameters->getItems(), $body->arguments->items->getItems())
			? $callee
			: null;
	}


	/**
	 * Whether the arguments are the parameters of the function, all of them, in their order and untouched.
	 * @param  list<ParameterNode>  $parameters
	 * @param  list<Node>  $arguments
	 */
	private static function passesParameters(array $parameters, array $arguments): bool
	{
		if (count($parameters) !== count($arguments)) {
			return false;
		}

		foreach ($parameters as $i => $parameter) {
			$argument = $arguments[$i];
			if (
				$parameter->ampersand !== null
				|| $parameter->default !== null
				|| $parameter->type !== null
				|| !$argument instanceof ArgumentNode
				|| $argument->name !== null
				|| $argument->ampersand !== null
				|| ($argument->ellipsis === null) !== ($parameter->ellipsis === null)
				|| !$argument->value instanceof Expression\VariableNode
				|| $argument->value->plainName !== $parameter->variable->plainName
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether the function takes one variadic parameter and unpacks it into the call, which keeps the arity. */
	private static function forwardsVariadic(ExpressionNode $function): bool
	{
		assert($function instanceof Expression\ClosureNode || $function instanceof Expression\ArrowFunctionNode);
		$parameters = $function->parameters->getItems();
		return count($parameters) === 1
			&& $parameters[0]->ellipsis !== null
			&& $parameters[0]->type === null;
	}


	/** Whether the name is one the scope gives its meaning to, which a first-class callable resolves at once. */
	private static function isScopeRelative(string $name): bool
	{
		return in_array(strtolower(ltrim($name, '\\')), ['self', 'parent', 'static'], true);
	}


	/** The expression a closure body consists of returning, null where the body does anything else. */
	private static function readSingleReturn(Expression\ClosureNode $closure): ?ExpressionNode
	{
		$statements = $closure->body->statements->getItems();
		return count($statements) === 1 && $statements[0] instanceof ReturnNode
			? $statements[0]->expression
			: null;
	}


	/**
	 * Whether the expression names a class rather than an object, which only `X::class` says of itself.
	 * @phpstan-assert-if-true Expression\ClassConstantFetchNode $expression
	 */
	private static function namesClass(ExpressionNode $expression): bool
	{
		return $expression instanceof Expression\ClassConstantFetchNode
			&& $expression->name instanceof IdentifierNode
			&& strcasecmp($expression->name->text, 'class') === 0;
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
