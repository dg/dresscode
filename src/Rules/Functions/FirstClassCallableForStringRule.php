<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\PhpSignatures;
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage, Violation};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentNode, ArrayItemNode, ClassLikeNode, ExpressionNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, FunctionCallNode, StaticMethodCallNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\{ClassNode, TraitNode};
use function count;


/**
 * A callable that names `self`, `parent` or `static` in a string or an array, `'self::sort'` or `['parent', 'boot']`,
 * which PHP 8.2 deprecated, is written as the first-class callable `self::sort(...)`, which resolves the name in the
 * same scope. Only an argument the code shows to be a callable is taken, one of a parameter of a function of PHP
 * declared `callable` or of `Closure::fromCallable()`: anywhere else the string may be text, and the closure would
 * not be. The callable must stand in a class, where the first-class callable compiles, and `parent` in one that has
 * a parent or in a trait. A method named with its class in the array, `[$this, 'parent::boot']`, is reported only.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=8.1'],
	decisions: ['upgrading.syntax.firstClassCallables'],
	analyses: [PhpSignatures::class, NameResolver::class],
)]
final class FirstClassCallableForStringRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class, StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof FunctionCallNode && !$node instanceof StaticMethodCallNode)
			|| !array_any($node->arguments->items->getItems(), fn(Node $argument) => $argument instanceof ArgumentNode && self::readCallable($argument->value) !== null)
		) {
			return;
		}

		$uncertainty = $node instanceof FunctionCallNode ? GlobalCalls::findUncertainty($node, $context) : null;
		foreach (self::findCallableArguments($node, $context) as $argument) {
			$callable = self::readCallable($argument->value);
			if ($callable === null) {
				continue;
			}

			[$class, $method] = $callable;
			$fixable = $method !== null && self::canWrite($argument, $class);
			if (
				$context->report(
					$argument->value,
					'The callable ' . Violation::formatCode($argument->value->text) . ' is deprecated since PHP 8.2.',
					fixable: $fixable,
					risk: $uncertainty === null ? null : Risk::NameUncertain,
					because: $uncertainty,
				)
				&& $fixable
			) {
				// Closure::fromCallable() of a first-class callable is the callable itself
				($node instanceof StaticMethodCallNode ? $node : $argument->value)->replaceWith((new Builder)->expression("$class::$method(...)"));
			}
		}
	}


	/**
	 * The scope-relative class and the method the value names as a callable; the method is null for a method named
	 * with its class in an array, which has no first-class form; null for any other value.
	 * @return ?array{string, ?string}
	 */
	private static function readCallable(ExpressionNode $value): ?array
	{
		if ($value instanceof StringNode) {
			$parts = explode('::', $value->toValue());
			return count($parts) === 2 && ForwardingClosure::isScopeRelative($parts[0]) && preg_match('~^\w+$~D', $parts[1])
				? [strtolower($parts[0]), $parts[1]]
				: null;
		}

		$items = $value instanceof ArrayNode ? $value->items->getItems() : [];
		[$first, $second] = [$items[0] ?? null, $items[1] ?? null];
		if (
			count($items) !== 2
			|| !$first instanceof ArrayItemNode || $first->key !== null || $first->ellipsis !== null
			|| !$second instanceof ArrayItemNode || $second->key !== null || $second->ellipsis !== null
			|| !$second->value instanceof StringNode
		) {
			return null;
		}

		$method = $second->value->toValue();
		return match (true) {
			str_contains($method, '::') => ['', null],
			$first->value instanceof StringNode && ForwardingClosure::isScopeRelative($first->value->toValue()) && preg_match('~^\w+$~D', $method) === 1
				=> [strtolower($first->value->toValue()), $method],
			default => null,
		};
	}


	/**
	 * The arguments the call passes to a parameter declared `callable`, of a function of PHP or of
	 * `Closure::fromCallable()`.
	 * @return list<ArgumentNode>
	 */
	private static function findCallableArguments(FunctionCallNode|StaticMethodCallNode $call, RuleContext $context): array
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if ($call instanceof StaticMethodCallNode) {
			$argument = $call->arguments->findArgument('callback', 0);
			return $call->class instanceof NameNode
				&& $call->name instanceof IdentifierNode
				&& $call->name->equals('fromCallable')
				&& $resolver->resolveClass($call->class) === 'Closure'
				&& $argument !== null
					? [$argument]
					: [];
		} elseif (!$call->name instanceof NameNode || !$resolver->isGlobalFunctionCall($call)) {
			return [];
		}

		$arguments = [];
		$parameters = $context->getAnalysis(PhpSignatures::class)->findParameters(ltrim($resolver->resolveFunction($call->name), '\\')) ?? [];
		foreach ($parameters as $position => $parameter) {
			$argument = $parameter->variadic ? null : $call->arguments->findArgument($parameter->name, $position);
			if ($argument !== null && in_array('callable', explode('|', (string) $parameter->type), true)) {
				$arguments[] = $argument;
			}
		}

		return $arguments;
	}


	/** Whether the first-class callable of the class compiles where the argument stands. */
	private static function canWrite(ArgumentNode $argument, string $class): bool
	{
		$owner = $argument->findAncestor(ClassLikeNode::class);
		return match (true) {
			$owner === null => false,
			$class !== 'parent', $owner instanceof TraitNode => true,
			$owner instanceof ClassNode, $owner instanceof AnonymousClassNode => $owner->extends !== null,
			default => false,
		};
	}
}
