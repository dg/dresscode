<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Parameter;
use DressCode\Analyses\PhpSignatures;
use DressCode\Analyses\PhpSymbols;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\DeclareNode;
use PhpSyntax\ParseException;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function array_any, count, in_array;


/**
 * A callable is called directly, not through `call_user_func()`: `$callback($a)`, `($this->handler)($a)`,
 * `strtoupper($a)`, and `call_user_func_array($callback, $args)` is `$callback(...$args)` from PHP 8.1 on, where
 * unpacking takes the string keys the function passes as named arguments. A callable written as an array or as
 * a string naming a method is left alone: whether `[$x, 'run']` holds an object or a class is not in the code.
 *
 * Two things the direct call does differently make a fix risky. It passes a variable, a property or an element by
 * reference where the function declares the parameter so, which `call_user_func()` never does; that is ruled out
 * only for a callable whose parameters are in sight, a closure or a function of PHP. And the function, being
 * internal, hands the arguments over as a caller without strict types, so in a file declaring them the direct
 * call may throw a TypeError where the old one converted; unless PHP compiles the call into a direct one itself,
 * which it does where it knows the name is the global function and no argument is named or unpacked.
 */
#[RuleInfo(
	'dresscode/no-call-user-func',
	Stage::Structure,
	description: 'Calls a callable directly instead of through call_user_func()',
	group: Group::Cleanup,
	requires: ['php' => '>=7.0'],
)]
final class NoCallUserFuncRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$resolver->isGlobalFunctionCall($node)
			|| $node->arguments->isPartialApplication()
		) {
			return;
		}

		$function = strtolower($resolver->resolveFunction($node->name));
		$args = $node->arguments->items->getItems();
		$callable = $args[0] ?? null;
		if (
			!in_array($function, ['call_user_func', 'call_user_func_array'], true)
			|| !$callable instanceof ArgumentNode
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
				|| version_compare($context->getPhpVersion(), '8.1', '<')
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
		$fixable = $node->name->getLastToken()?->hasCommentUpTo($end) === false;
		$uncertainty = NodeHelpers::findUncertainty($node, $context);
		$risky = $uncertainty !== null
			|| (array_any($passed, fn(ExpressionNode $value) => $value->isWritable()) && !$this->isWithoutReferences($callable->value, $context))
			|| ($passed !== [] && self::declaresStrictTypes($context) && !$this->isCompiledDirectly($node, $function, $context));

		if (!$context->report(
			$node->name,
			"The callable must be called directly, not through $function()" . $uncertainty,
			fixable: $fixable,
			risky: $fixable && $risky,
		)) {
			return;
		}

		$call = $named ?? FunctionCallNode::of(clone $callable->value);
		if ($function === 'call_user_func_array') {
			$template = (new Parser)->parseExpression('f(...$a)');
			assert($template instanceof FunctionCallNode && $template->arguments->items->getItems()[0] instanceof ArgumentNode);
			$value = $passed[0]->withoutEdgeTrivia();
			$template->arguments->items->getItems()[0]->value->replaceWith($value);
			$call->arguments = clone $template->arguments;
		} else {
			// what follows the call stays with the node it replaces, so the arguments must not bring it a second time
			$arguments = clone $node->arguments;
			$arguments->items->removeItem($arguments->items->getItems()[0]);
			$arguments->closeParen->setTrailingTrivia([]);
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
		$bare = $resolver->getNamespace($callable) === '' && !isset($resolver->getFunctionImports($callable)[strtolower($name)]);
		try {
			$call = (new Parser)->parseExpression(($bare ? '' : '\\') . $name . '()');
		} catch (ParseException) {
			return null;
		}

		return $call instanceof FunctionCallNode && $call->name instanceof NameNode && !$call->name->isKeyword()
			? $call
			: null;
	}


	/** Whether none of the parameters of the callable takes an argument by reference, as far as the code shows them. */
	private function isWithoutReferences(ExpressionNode $callable, RuleContext $context): bool
	{
		if ($callable instanceof ClosureNode || $callable instanceof ArrowFunctionNode) {
			return !array_any($callable->parameters->getItems(), fn(ParameterNode $parameter) => $parameter->ampersand !== null);
		}

		$name = $callable instanceof StringNode ? self::findFunctionName($callable) : null;
		$parameters = $name !== null && $context->getAnalysis(PhpSymbols::class)->isInternalFunction($name)
			? $context->getAnalysis(PhpSignatures::class)->findParameters($name)
			: null;
		return $parameters !== null && !array_any($parameters, fn(Parameter $parameter) => $parameter->byReference);
	}


	/** Whether PHP compiles the call into a direct call of the callable, which checks the types as the file does. */
	private function isCompiledDirectly(FunctionCallNode $call, string $function, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$name = $call->name;
		return $name instanceof NameNode
			&& (
				$name->kind === NameKind::FullyQualified
				|| $resolver->getNamespace($call) === ''
				|| isset($resolver->getFunctionImports($call)[strtolower($name->text)])
			)
			&& NodeHelpers::isOptimizedCall($call, $function, $context);
	}


	/** The function a string callable names, without a leading backslash; null for a method or anything but a name. */
	private static function findFunctionName(StringNode $string): ?string
	{
		return preg_match('~^\\\\?([a-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[a-z_\x80-\xff][\w\x80-\xff]*)*)$~iD', $string->value, $m)
			? $m[1]
			: null;
	}


	private static function declaresStrictTypes(RuleContext $context): bool
	{
		foreach ($context->getFile()->statements->getItems() as $statement) {
			if ($statement instanceof DeclareNode) {
				foreach ($statement->items->getItems() as $item) {
					if (strtolower($item->name->token->text) === 'strict_types' && trim((string) $item->value) === '1') {
						return true;
					}
				}
			}
		}

		return false;
	}
}
