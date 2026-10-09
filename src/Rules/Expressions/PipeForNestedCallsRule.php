<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\{PhpSignatures, Types};
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, DereferenceKind, Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, Expression, ExpressionNode, IdentifierNode, NameNode, OperatorNode};
use function count;


/**
 * Calls nested one in another, each handing its result to the next, are the pipe operator of PHP 8.5, which
 * puts them in the order they run: `trim(strtolower($s))` reads outwards in and `$s |> strtolower(...) |> trim(...)`
 * downwards. Every call of the nest must take the one argument and name what it calls, so that the step is
 * a first-class callable; `upgrading.syntax.pipe.minCalls` says how deep a nest must be before the pipe pays for
 * itself.
 *
 * A nest standing inside an operator that binds tighter than the pipe stays as it is: the pipe binds loosely,
 * between concatenation and comparison, so it would need parentheses there and read worse than the nest.
 *
 * A pipe cannot pass its value by reference, so a nest with a step taking it so stays as it is. What a step takes
 * says the declaration of PHP or of the file, and of a method the types; the fix is risky where nothing says it,
 * which is a function of another file and without the types every method. A receiver that runs code is risky too
 * where its call takes code that runs: the nest evaluates the receiver before that code, the pipe after it.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=8.5'],
	analyses: [PhpSignatures::class, Types::class, NameResolver::class],
)]
final class PipeForNestedCallsRule extends NodeRule
{
	private int $minCalls = 3;


	public static function getDecisions(): array
	{
		return [
			new Decision('upgrading.syntax.pipe.nestedCalls', Domain::adopted(), 'Calls nested one in another, each handing its result to the next, written with the pipe operator of PHP 8.5 in the order they run: `$x |> a(...) |> b(...)` for `b(a($x))`'),
			new Decision('upgrading.syntax.pipe.minCalls', new Count(2, range: false), 'The calls a nest has at least for the pipe operator to be written', parameter: true, default: 3),
		];
	}


	public function configure(Values $values): void
	{
		$this->minCalls = $values->get('upgrading.syntax.pipe.minCalls')->getCount()[0];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\MethodCallNode::class, Expression\StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionNode) {
			return;
		}

		$steps = $calls = [];
		$inner = $node;
		while (($callee = self::readCallee($inner)) !== null) {
			$steps[] = $callee;
			assert($inner instanceof Expression\FunctionCallNode || $inner instanceof Expression\MethodCallNode || $inner instanceof Expression\StaticMethodCallNode);
			$calls[] = $inner;
			$argument = $inner->arguments->items->getItems()[0];
			assert($argument instanceof ArgumentNode);
			$inner = $argument->value;
		}

		if (
			count($steps) < $this->minCalls
			|| self::bindsTighter($node)
			// the nest is read from its outermost call, so a call standing in one is left to it
			|| ($node->parent instanceof ArgumentNode && self::readCallee($node->parent->findAncestor(ArgumentListNode::class)?->parent) !== null)
			|| $node->hasInnerComment()
		) {
			return;
		}

		$byValue = array_map(fn(ExpressionNode $call) => self::takesByValue($call, $context), $calls);
		if (in_array(false, $byValue, true)) {
			return;
		}

		[$risk, $because] = match (true) {
			array_any($calls, fn(ExpressionNode $call, int $i) => $call instanceof Expression\MethodCallNode
				&& $call->object->hasEffect()
				&& ($i < count($calls) - 1 || $inner->hasEffect()))
				=> [Risk::BehaviorChanges, 'a receiver runs code, which the pipe runs after the code its call takes'],
			array_any($calls, fn(ExpressionNode $call, int $i) => $byValue[$i] === null && $call instanceof Expression\FunctionCallNode)
				=> [Risk::BehaviorChanges, 'a function of another file may take its argument by reference, which the pipe cannot pass'],
			in_array(null, $byValue, true) => [Risk::TypeUnknown, 'a step may take its argument by reference, which the pipe cannot pass'],
			default => [null, null],
		};
		if (!$context->report($node, 'The nested calls must be written with the pipe operator.', risk: $risk, because: $because)) {
			return;
		}

		$pipe = implode(' |> ', array_map(fn(string $callee) => "$callee(...)", array_reverse($steps)));
		$replacement = (new Builder)->expression("0 |> $pipe");
		$first = $replacement;
		while ($first instanceof Expression\BinaryOpNode) {
			$first = $first->left;
		}

		$first->replaceWithExpression($inner->withoutEdgeTrivia());
		$node->replaceWith($replacement);
	}


	/**
	 * How a call that takes one argument and passes it on names what it calls, null for anything else,
	 * a call of several arguments among them, which no step of a pipe can be.
	 */
	private static function readCallee(?Node $call): ?string
	{
		if (
			!$call instanceof Expression\FunctionCallNode
			&& !$call instanceof Expression\MethodCallNode
			&& !$call instanceof Expression\StaticMethodCallNode
		) {
			return null;
		}

		$argument = $call->arguments->items->getItems()[0] ?? null;
		if (
			count($call->arguments->items) !== 1
			|| !$argument instanceof ArgumentNode
			|| $argument->name !== null || $argument->ampersand !== null || $argument->ellipsis !== null
		) {
			return null;
		}

		return match (true) {
			$call instanceof Expression\FunctionCallNode => $call->name instanceof NameNode ? $call->name->text : null,
			$call instanceof Expression\MethodCallNode => $call->name instanceof IdentifierNode
				&& $call->object->isDereferenceable(DereferenceKind::Fetch)
				&& !$call->isInNullsafeChain()
					? $call->object->text . $call->operator->text . $call->name->text
					: null,
			default => $call->name instanceof IdentifierNode && $call->class instanceof NameNode
				? $call->class->text . '::' . $call->name->text
				: null,
		};
	}


	/**
	 * Whether the call takes its argument by value, as the declaration in the file or the one of PHP says, and of
	 * a method the types; null where nothing tells.
	 */
	private static function takesByValue(ExpressionNode $call, RuleContext $context): ?bool
	{
		if ($call instanceof Expression\FunctionCallNode && $call->name instanceof NameNode) {
			$resolver = $context->getAnalysis(NameResolver::class);
			$function = $resolver->resolveFunction($call->name);
			$declaration = $resolver->findDeclaration($function, SymbolKind::Function);
			if ($declaration !== null) {
				return ($declaration->parameters->getItems()[0] ?? null)?->ampersand === null;
			}

			$parameters = $resolver->isGlobalFunctionCall($call)
				? $context->getAnalysis(PhpSignatures::class)->findParameters($function)
				: null;

		} else {
			$types = $context->findAnalysis(Types::class);
			$access = $types?->findMemberAccess($call);
			$parameters = $access === null ? null : $types->findParameters($access);
		}

		return $parameters === null ? null : !($parameters[0]->byReference ?? false);
	}


	/** Whether what stands around the expression binds tighter than the pipe, which would need parentheses. */
	private static function bindsTighter(ExpressionNode $expression): bool
	{
		static $pipe = (new Builder)->binary(0, '|>', 0)->precedence;
		$parent = $expression->parent;
		return $expression->isDereferenced()
			|| ($parent instanceof OperatorNode && $parent->precedence > $pipe);
	}
}
