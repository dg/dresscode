<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\{Parameter, PhpSignatures, PhpSymbols};
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{Compiler, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, ParseException, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, NameNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, ArrowFunctionNode, ClosureNode, FunctionCallNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\DeclareNode;
use function count;


/**
 * A callable is called directly, not through `call_user_func()`: `$callback($a)`, `($this->handler)($a)`,
 * `strtoupper($a)`, and `call_user_func_array($callback, $args)` is `$callback(...$args)` from PHP 8.1 on, where
 * unpacking takes the string keys the function passes as named arguments. A callable written as an array or as
 * a string naming a method is left alone: whether `[$x, 'run']` holds an object or a class is not in the code.
 *
 * Two things the direct call does differently make a fix risky. It passes a variable, a property or an element by
 * reference where the function declares the parameter so, which `call_user_func()` never does; that is ruled out
 * only for a callable whose parameters are in sight, a closure or a function of PHP. And `call_user_func()`,
 * being internal, hands the arguments over as a caller without strict types, so in a file declaring them the direct
 * call may throw a TypeError where the old one converted; unless PHP compiles the call into a direct one itself,
 * which it does where it knows the name is the global function and no argument is named or unpacked.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=7.0'],
	analyses: [PhpSignatures::class, PhpSymbols::class, NameResolver::class],
)]
final class NoCallUserFuncRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('cleanup.call_user_func', Domain::state('forbidden'), '`call_user_func($f, $x)` is `$f($x)`, `call_user_func_array($f, $a)` is `$f(...$a)`')];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| ($function = GlobalCalls::findFunction($node, ['call_user_func' => true, 'call_user_func_array' => true], $context)) === null
			|| $node->arguments->isPartialApplication()
		) {
			return;
		}

		$args = $node->arguments->items->getItems();
		$callable = $args[0] ?? null;
		if (
			!$callable instanceof ArgumentNode
			|| $callable->name !== null
			|| $callable->ellipsis !== null
			|| $callable->value instanceof ArrayNode
		) {
			return;
		}

		$named = $callable->value instanceof StringNode ? self::buildNamedCall($callable->value, $context) : null;
		if ($callable->value instanceof StringNode && $named === null) {
			return;
		}

		if ($function === 'call_user_func_array') {
			$array = $args[1] ?? null;
			if (
				count($args) !== 2
				|| !$array instanceof ArgumentNode
				|| $array->name !== null
				|| $array->ellipsis !== null
				|| version_compare($context->phpVersion, '8.1', '<')
			) {
				return;
			}

			$passed = [$array->value];
			$end = $node->arguments->closeParen;

		} else {
			$passed = array_map(fn(ArgumentNode $arg) => $arg->value, array_filter(array_slice($args, 1), fn($arg) => $arg instanceof ArgumentNode));
			$end = ($args[1] ?? null)?->getFirstToken() ?? $node->arguments->closeParen;
		}

		// a comment between the name and the arguments the call keeps would be lost with them
		$fixable = !$node->name->getLastToken()->hasCommentUpTo($end);
		$uncertainty = GlobalCalls::findUncertainty($node, $context);
		$because = match (true) {
			array_any($passed, fn(ExpressionNode $value) => $value->isWritable()) && self::mayTakeReference($callable->value, $context)
				=> 'the callable may take a parameter by reference, which the direct call passes as one',
			$passed !== [] && self::declaresStrictTypes($context) && !self::isCompiledDirectly($node, $function, $context)
				=> 'under `strict_types` the direct call refuses an argument `' . $function . '()` converted',
			default => null,
		};

		if (!$context->report(
			$node->name,
			"The callable must be called directly, not through `$function()`" . ($fixable ? '.' : ', but a comment stands among its arguments.'),
			fixable: $fixable,
			risk: $because !== null ? Risk::BehaviorChanges : ($uncertainty === null ? null : Risk::NameUncertain),
			because: $because ?? $uncertainty,
		)) {
			return;
		}

		$call = $named ?? (new Builder)->call($callable->value);
		if ($function === 'call_user_func_array') {
			$builder = new Builder;
			$call->arguments = $builder->arguments([$builder->fragment(ArgumentNode::class, '...$arguments', arguments: $passed[0])]);
		} else {
			// what follows the call stays with the node it replaces, so the arguments must not bring it a second time
			$arguments = $node->arguments->withoutEdgeTrivia();
			$arguments->items->removeItem($arguments->items->getItems()[0]);
			$call->arguments = $arguments;
		}

		$node->replaceWith($call);
	}


	/**
	 * The call of the function a string callable names, without arguments, its name written bare only where nothing
	 * but the global function answers to it; null for a string naming a method, a keyword, or anything but a name.
	 */
	private static function buildNamedCall(StringNode $callable, RuleContext $context): ?FunctionCallNode
	{
		$name = self::findFunctionName($callable);
		if ($name === null) {
			return null;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$bare = $resolver->getNamespace($callable) === '' && !str_contains($name, '\\') && !isset($resolver->getImports(SymbolKind::Function, $callable)[strtolower($name)]);
		try {
			$call = (new Builder)->expression(($bare ? '' : '\\') . $name . '()');
		} catch (ParseException) {
			return null;
		}

		return $call instanceof FunctionCallNode && $call->name instanceof NameNode && !$call->name->isKeyword()
			? $call
			: null;
	}


	/**
	 * Whether a parameter of the callable may take an argument by reference: one does, or the code does not show the
	 * parameters, which rules nothing out.
	 */
	private static function mayTakeReference(ExpressionNode $callable, RuleContext $context): bool
	{
		if ($callable instanceof ClosureNode || $callable instanceof ArrowFunctionNode) {
			return array_any($callable->parameters->getItems(), fn(ParameterNode $parameter) => $parameter->ampersand !== null);
		}

		$name = $callable instanceof StringNode ? self::findFunctionName($callable) : null;
		$parameters = $name !== null && $context->getAnalysis(PhpSymbols::class)->isBuiltinFunction($name)
			? $context->getAnalysis(PhpSignatures::class)->findParameters($name)
			: null;
		return $parameters === null || array_any($parameters, fn(Parameter $parameter) => $parameter->byReference);
	}


	/** Whether PHP compiles the call into a direct call of the callable, which checks the types as the file does. */
	private static function isCompiledDirectly(FunctionCallNode $call, string $function, RuleContext $context): bool
	{
		return $call->name instanceof NameNode
			&& Compiler::isNameKnown($call->name, SymbolKind::Function, $context)
			&& Compiler::isOptimizedCall($call, $function, $context);
	}


	/** The function a string callable names, without a leading backslash; null for a method or anything but a name. */
	private static function findFunctionName(StringNode $string): ?string
	{
		return preg_match('~^\\\\?([a-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[a-z_\x80-\xff][\w\x80-\xff]*)*)$~iD', $string->toValue(), $m)
			? $m[1]
			: null;
	}


	private static function declaresStrictTypes(RuleContext $context): bool
	{
		return array_any($context->file->statements->getItems(), fn($statement) => $statement instanceof DeclareNode && $statement->findDirective('strict_types')?->value->text === '1');
	}
}
