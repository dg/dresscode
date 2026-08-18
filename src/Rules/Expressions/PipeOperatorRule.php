<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\PhpSignatures;
use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{AccessKind, Node, Parser, SymbolKind, Token};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, Expression, ExpressionNode, IdentifierNode, NameNode, OperatorNode};
use function count;


/**
 * Calls nested one in another, each handing its result to the next, are the pipe operator of PHP 8.5, which
 * puts them in the order they run: `trim(strtolower($s))` reads outwards in and `$s |> strtolower(...) |> trim(...)`
 * downwards. Every call of the nest must take the one argument and name what it calls, so that the step is
 * a first-class callable; `minimumCalls` says how deep a nest must be before the pipe pays for itself.
 *
 * A nest standing inside an operator that binds tighter than the pipe stays as it is: the pipe binds loosely,
 * between concatenation and comparison, so it would need parentheses there and read worse than the nest.
 *
 * A pipe cannot pass its value by reference, so a nest with a step taking it so stays as it is. What a step takes
 * says the declaration of PHP or of the file; the fix is risky where nothing says it, which is every method and
 * a function of another file.
 */
#[RuleInfo(
	'dresscode/pipe-operator',
	Stage::Structure,
	description: 'Writes nested calls passing one result into the next with the pipe operator',
	group: Group::Modernization,
	requires: ['php' => '>=8.5'],
	decision: 'minimumCalls',
)]
final class PipeOperatorRule extends NodeRule implements ConfigurableRule
{
	/** the precedence of |> */
	private const Precedence = 183;

	private int $minimumCalls = 3;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minimumCalls' => Expect::int(3)->min(2)
				->description('How many calls the nest must hold before the pipe is asked for'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minimumCalls = $options['minimumCalls'];
	}


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\MethodCallNode::class, Expression\StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ExpressionNode
			|| $node->hasComment()
			|| self::bindsTighter($node)
			// the nest is read from its outermost call, so a call standing in one is left to it
			|| ($node->parent instanceof ArgumentNode && self::readCallee($node->parent->findAncestor(ArgumentListNode::class)?->parent) !== null)
		) {
			return;
		}

		$steps = $byValue = [];
		$inner = $node;
		while (($callee = self::readCallee($inner)) !== null) {
			$steps[] = $callee;
			assert($inner instanceof Expression\FunctionCallNode || $inner instanceof Expression\MethodCallNode || $inner instanceof Expression\StaticMethodCallNode);
			$byValue[] = self::takesByValue($inner, $context);
			$argument = $inner->arguments->items->getItems()[0];
			assert($argument instanceof ArgumentNode);
			$inner = $argument->value;
		}

		$unknown = in_array(null, $byValue, true);
		if (
			count($steps) < $this->minimumCalls
			|| in_array(false, $byValue, true)
			|| !$context->report(
				$node,
				'The nested calls must be written with the pipe operator' . ($unknown ? ', which fails on a parameter by reference' : ''),
				risky: $unknown,
			)
		) {
			return;
		}

		$pipe = implode(' |> ', array_map(fn(string $callee) => "$callee(...)", array_reverse($steps)));
		$replacement = (new Parser)->parseExpression("0 |> $pipe");
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
			|| $argument->name || $argument->ampersand || $argument->ellipsis
		) {
			return null;
		}

		return match (true) {
			$call instanceof Expression\FunctionCallNode => $call->name instanceof NameNode ? $call->name->text : null,
			$call instanceof Expression\MethodCallNode => $call->name instanceof IdentifierNode && $call->object->isDereferenceable(AccessKind::Member)
				? $call->object->text . $call->operator->text . $call->name->text
				: null,
			default => $call->name instanceof IdentifierNode && $call->class instanceof NameNode
				? $call->class->text . '::' . $call->name->text
				: null,
		};
	}


	/**
	 * Whether the call takes its argument by value, as the declaration in the file or the one of PHP says; null
	 * where nothing tells.
	 */
	private static function takesByValue(ExpressionNode $call, RuleContext $context): ?bool
	{
		if (!$call instanceof Expression\FunctionCallNode || !$call->name instanceof NameNode) {
			return null;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = $resolver->resolveFunction($call->name);
		$declaration = $resolver->findDeclaration($function, SymbolKind::Function);
		if ($declaration !== null) {
			return ($declaration->parameters->getItems()[0] ?? null)?->ampersand === null;
		}

		$parameters = $resolver->isGlobalFunctionCall($call)
			? $context->getAnalysis(PhpSignatures::class)->findParameters($function)
			: null;
		return $parameters === null ? null : !($parameters[0]->byReference ?? false);
	}


	/** Whether what stands around the expression binds tighter than the pipe, which would need parentheses. */
	private static function bindsTighter(ExpressionNode $expression): bool
	{
		$parent = $expression->parent;
		return $expression->isDereferenced()
			|| ($parent instanceof OperatorNode && $parent->getPrecedence()[0] > self::Precedence);
	}
}
