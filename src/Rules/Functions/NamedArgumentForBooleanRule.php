<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\{Parameter, PhpSignatures, PhpSymbols, Types};
use DressCode\{Decision, DecisionKind, Domain, GlobalCalls, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate, Values};
use DressCode\Domains\Names;
use DressCode\Rules\Compiler;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, MethodCallNode, NewNode, StaticMethodCallNode};
use PhpSyntax\Nodes\Scalar\BooleanNode;
use function count;


/**
 * A `true` or `false` passed by position to a parameter declared `bool` is passed by the name of the parameter, which
 * says what it switches: `json_decode($json, associative: true)`. Only the arguments no positional one follows are
 * named, all of a call at once. A call of a function PHP calls without a frame or the compiler optimizes stays as it is,
 * a named argument preventing both. The parameters of a function of PHP are those of its catalog; of any other
 * function and of a method the types of the code find them, without which the call is left alone. A setter,
 * `setVisible(true)`, says by its name what the value switches and stays as it is. Where an instance of a child may
 * run the method, `$this->render(true)` of a class that is not final, the child may name the parameters otherwise,
 * and the fix is risky.
 */
#[RuleInfo(
	Stage::Structure,
	analyses: [PhpSignatures::class, PhpSymbols::class, NameResolver::class, Types::class],
	requires: ['php' => '>=8.0'],
)]
final class NamedArgumentForBooleanRule extends NodeRule
{
	private const Except = 'functions.namedBooleanArgumentExcept';

	/** @var array<string, true>  lowercased function or `Class::method` => whose arguments stay positional */
	private array $except = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'functions.namedBooleanArgument',
				Domain::state('required'),
				'A `true` or `false` passed by position to a parameter declared `bool`, written with the name of the parameter, `json_decode($json, associative: true)`; not where a positional argument follows it, nor in a call of a setter, `setVisible(true)`, or of a function PHP calls without a frame or optimizes',
			),
			new Decision(
				self::Except,
				new Names,
				'The functions and methods whose arguments stay positional, a method written with the class declaring it or the one it is called on, `Acme\Shop\Cart::add`',
				kind: DecisionKind::Parameter,
				default: [],
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach ($values->get(self::Except)->getNames() as $name) {
			$this->except[strtolower(ltrim($name, '\\'))] = true;
		}
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class, MethodCallNode::class, StaticMethodCallNode::class, NewNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$arguments = $node instanceof FunctionCallNode
			|| $node instanceof MethodCallNode
			|| $node instanceof StaticMethodCallNode
			|| $node instanceof NewNode
			? $node->arguments
			: null;
		if (
			$arguments === null
			|| !array_any(
				$arguments->items->getItems(),
				fn(Node $item) => $item instanceof ArgumentNode && $item->value instanceof BooleanNode,
			)
			|| $arguments->isPartialApplication()
			|| ($found = $node instanceof FunctionCallNode
				? $this->findFunction($node, $context)
				: $this->findMethod($node, $context)) === null
		) {
			return;
		}

		[$function, $parameters, $risk, $because] = $found;
		$named = self::findNamed($arguments, $parameters);
		$names = implode(', ', array_map(fn(array $pair) => "`{$pair[1]}`", $named));
		if (
			$named === []
			|| !$context->report(
				$named[0][0],
				(count($named) === 1 ? 'The boolean argument' : 'The boolean arguments') . " of `$function()` must be named $names.",
				risk: $risk,
				because: $because,
			)
		) {
			return;
		}

		foreach ($named as [$argument, $name]) {
			$first = $argument->value->getFirstToken();
			$identifier = IdentifierNode::fromText($name);
			$identifier->token->setLeadingTrivia($first->leadingTrivia);
			$first->setLeadingTrivia([]);
			$argument->name = $identifier;
			$argument->colon = Token::fromText(':')->setTrailingSpace(' ');
		}
	}


	/**
	 * The function the call calls, its parameters, and the risk with its reason; null for a call of an exception, of a
	 * function PHP optimizes, and of one whose parameters are unknown.
	 * @return ?array{string, list<Parameter>, ?Risk, ?string}
	 */
	private function findFunction(FunctionCallNode $call, RuleContext $context): ?array
	{
		if (!$call->name instanceof NameNode) {
			return null;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = ltrim($resolver->resolveFunction($call->name), '\\');
		$parameters = $resolver->isGlobalFunctionCall($call)
			? $context->getAnalysis(PhpSignatures::class)->findParameters($function)
			: null;
		if (isset($this->except[strtolower($function)])) {
			return null;
		} elseif ($parameters === null) {
			$parameters = $context->findAnalysis(Types::class)?->findFunctionParameters($function);
			return $parameters === null ? null : [$function, $parameters, null, null];
		}

		$count = count($call->arguments->items->getItems());
		if (
			$context->getAnalysis(PhpSymbols::class)->findFramelessParameterNames($function, $count) !== null
			|| Compiler::isOptimizedCall($call, $function, $context)
		) {
			return null;
		}

		$uncertainty = GlobalCalls::findUncertainty($call, $context);
		return [$function, $parameters, $uncertainty === null ? null : Risk::NameUncertain, $uncertainty];
	}


	/**
	 * The method the call calls, its parameters as the types find them, and the risk with its reason; null for a call
	 * of an exception and of a method whose parameters are unknown.
	 * @return ?array{string, list<Parameter>, ?Risk, ?string}
	 */
	private function findMethod(MethodCallNode|StaticMethodCallNode|NewNode $call, RuleContext $context): ?array
	{
		$types = $context->findAnalysis(Types::class);
		$access = $call instanceof NewNode ? $types?->findConstructorAccess($call) : $types?->findMemberAccess($call);
		if ($types === null || $access === null || preg_match('~^set[A-Z]~', $access->name) === 1) {
			return null;
		}

		$parameters = $types->findParameters($access);
		$classes = [...$access->classes, $types->findMember($call)->declaringClass ?? null];
		if (
			$parameters === null
			|| array_any($classes, fn(?string $class) => isset($this->except[strtolower("$class::$access->name")]))
		) {
			return null;
		}

		// a call made on an object, or on `static`, runs the method of the class the instance is of
		$late = $call instanceof MethodCallNode || !$call->class instanceof NameNode || strcasecmp($call->class->text, 'static') === 0;
		$risky = $late && array_any(
			$access->classes,
			fn(string $class) => $types->isOverridable($class, $access->name) !== Tristate::No,
		);
		return [
			$access->classes[0] . '::' . $access->name,
			$parameters,
			$risky ? Risk::TypeUnknown : null,
			$risky ? "a class declaring `$access->name()` again may name its parameters otherwise" : null,
		];
	}


	/**
	 * The arguments `true` or `false` that stand by position at the end of the call, before the named ones alone, with
	 * the names of the boolean parameters they are passed to, in the order they stand.
	 * @param  list<Parameter>  $parameters
	 * @return list<array{ArgumentNode, string}>
	 */
	private static function findNamed(ArgumentListNode $arguments, array $parameters): array
	{
		$named = [];
		$items = $arguments->items->getItems();
		foreach (array_reverse(array_keys($items)) as $position) {
			$item = $items[$position];
			$parameter = $parameters[$position] ?? null;
			if ($item instanceof ArgumentNode && $item->name !== null) {
				continue;
			} elseif (
				!$item instanceof ArgumentNode
				|| $item->ellipsis !== null
				|| !$item->value instanceof BooleanNode
				|| $parameter === null
				|| $parameter->variadic
				|| !self::isBoolean($parameter)
			) {
				break;
			}

			array_unshift($named, [$item, $parameter->name]);
		}

		return $named;
	}


	/** Whether the parameter is declared `bool`, or `?bool`. */
	private static function isBoolean(Parameter $parameter): bool
	{
		$types = explode('|', strtolower(ltrim($parameter->type ?? '', '?')));
		return array_values(array_diff($types, ['null'])) === ['bool'];
	}
}
