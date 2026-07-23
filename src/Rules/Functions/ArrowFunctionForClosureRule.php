<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Flag;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{AnonymousFunctionNode, AttributeNode, ClosureUseNode, ConstItemNode, ExpressionNode, FunctionLikeNode, ParameterNode, StatementNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, ArrayNode, ArrowFunctionNode, BinaryOpNode, ClosureNode, CombinedAssignmentNode, MatchNode, MethodCallNode, ParenthesizedNode, PostfixOpNode, PrefixOpNode, PropertyFetchNode, TernaryNode, VariableNode};
use PhpSyntax\Nodes\Member\{EnumCaseNode, PropertyItemNode};
use PhpSyntax\Nodes\Statement\ReturnNode;
use function count;


/**
 * A closure whose body is a single `return` becomes an arrow function; variables captured with `use` come
 * along automatically, one captured by reference does not, so such a closure stays. So does a closure whose
 * body reaches a variable by a name it does not spell out (`$$name`, `compact()`, `extract()`,
 * `get_defined_vars()`, `eval`, `include`), because an arrow function captures only the variables its
 * expression names, and a closure reading a variable it neither takes, uses nor assigns, which it sees undefined
 * where an arrow function would capture it. A comment before the parameters or anywhere after them keeps the
 * closure as well, and so does a constant expression, which takes a static closure but no arrow function.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class ArrowFunctionForClosureRule extends NodeRule
{
	private const Plain = 'functions.closureReturningOneExpression';
	private const Nested = 'functions.closureReturningOneExpressionNested';

	/** the variables every scope has, which an arrow function reads as the closure does */
	private const Bound = [
		'this' => true, 'GLOBALS' => true, '_SERVER' => true, '_GET' => true, '_POST' => true, '_FILES' => true,
		'_COOKIE' => true, '_SESSION' => true, '_REQUEST' => true, '_ENV' => true,
	];

	private bool $nested = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Plain, Domain::state(), 'A closure whose body is a single `return`, which is an arrow function where that is equivalent: no variable captured by reference, none reached by a name it does not spell out, no comment lost'),
			new Decision(self::Nested, new Flag, 'Whether such a closure holding another closure or arrow function is one too', parameter: true, default: false),
		];
	}


	public function configure(Values $values): void
	{
		$this->nested = $values->get(self::Nested)->getFlag();
	}


	public function getVisitedNodes(): array
	{
		return [ClosureNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClosureNode) {
			return;
		}

		$stmts = $node->body->statements->getItems();
		$return = $stmts[0] ?? null;
		if (
			count($stmts) !== 1
			|| !$return instanceof ReturnNode
			|| $return->expression === null
			|| ($node->staticKeyword ?? $node->functionKeyword)->hasCommentUpTo($node->openParen)
			|| $node->closeParen->hasCommentUpTo($node->body->closeBrace)
		) {
			return;
		}

		if (
			(!$this->nested && $node->body->find(AnonymousFunctionNode::class))
			|| NodeHelpers::findDynamicVariableAccesses($node->body, $context) !== []
			|| self::standsInConstantExpression($node)
			|| self::readsUnboundVariable($node, $return->expression)
		) {
			return;
		}

		foreach ($node->uses?->items->getItems() ?? [] as $use) {
			if ($use->ampersand !== null) {
				return;
			}
		}

		if (!$context->report($node->functionKeyword, 'The closure returning a single expression must be written as an arrow function.')) {
			return;
		}

		$fn = (new Builder)->fragment(
			ArrowFunctionNode::class,
			($node->staticKeyword ? 'static ' : '')
			. 'fn' . ($node->ampersand ? '&' : '') . '()'
			. ($node->returnType ? ': ' . $node->returnType->text : '')
			. ' => $value',
			value: $return->expression,
		);
		$fn->attributes = clone $node->attributes;
		$fn->parameters = clone $node->parameters;
		$node->replaceWith($fn);
	}


	/**
	 * Whether the expression the closure returns reads a variable of the closure that is neither a parameter, nor one
	 * of its `use`, nor `$this` or a superglobal, nor written before the read, which the closure reads as undefined
	 * and an arrow function would capture. A write counts once the expression that does it is over, and only where it
	 * surely runs: one behind a condition, a short circuit or a nullsafe operator may not, and a read standing before
	 * the write is ended, `$x + ($x = 1)`, has no evident order.
	 */
	private static function readsUnboundVariable(ClosureNode $closure, ExpressionNode $expression): bool
	{
		$bound = self::Bound;
		foreach ([...$closure->parameters->getItems(), ...$closure->uses?->items->getItems() ?? []] as $declared) {
			if (($name = $declared->variable->plainName) !== null) {
				$bound[$name] = true;
			}
		}

		/** @var \SplObjectStorage<Token, int> $order */
		$order = new \SplObjectStorage;
		$last = $expression->getLastToken();
		for ($token = $expression->getFirstToken(), $i = 0; $token !== null; $token = $token === $last ? null : $token->getNext()) {
			$order[$token] = $i++;
		}

		$written = $reads = [];
		foreach ([$expression, ...$expression->find(VariableNode::class)] as $variable) {
			if (!$variable instanceof VariableNode || ($name = $variable->plainName) === null || self::findScope($variable) !== $closure) {
				continue;
			}

			$writer = $variable->isWritten() ? self::findWriter($variable) : null;
			$writer = $writer !== null && isset($order[$writer->getLastToken()]) ? $writer : null;
			if (
				$writer === null
				|| $writer instanceof CombinedAssignmentNode
				|| $writer instanceof PrefixOpNode
				|| $writer instanceof PostfixOpNode
			) {
				$reads[] = [$name, $order[$variable->getFirstToken()]];
			}

			if ($writer !== null && self::runsSurely($writer, $expression)) {
				$written[$name] = min($written[$name] ?? PHP_INT_MAX, $order[$writer->getLastToken()]);
			}
		}

		return array_any($reads, fn(array $read) => !isset($bound[$read[0]]) && ($written[$read[0]] ?? PHP_INT_MAX) > $read[1]);
	}


	/** The expression whose evaluation writes the variable: the assignment, the step or the call taking it by reference. */
	private static function findWriter(VariableNode $variable): ?ExpressionNode
	{
		$node = $variable;
		while (
			!$node->parent instanceof ExpressionNode
			|| ($node->parent instanceof ArrayAccessNode && $node->parent->expression === $node)
			|| $node->parent instanceof ParenthesizedNode
			|| $node->parent instanceof ArrayNode
		) {
			if ($node->parent === null) {
				return null;
			}

			$node = $node->parent;
		}

		return $node->parent;
	}


	/** Whether the expression runs whenever the outer one does, behind no condition, short circuit or nullsafe operator. */
	private static function runsSurely(ExpressionNode $node, ExpressionNode $outer): bool
	{
		for (; $node !== $outer && $node->parent !== null; $node = $node->parent) {
			$parent = $node->parent;
			if (
				($parent instanceof TernaryNode && $parent->condition !== $node)
				|| ($parent instanceof MatchNode && $parent->subject !== $node)
				|| (
					$parent instanceof BinaryOpNode
					&& $parent->right === $node
					&& $parent->operator->is([Token::BooleanAnd, Token::BooleanOr, Token::LogicalAnd, Token::LogicalOr, Token::Coalesce])
				)
				|| (($parent instanceof MethodCallNode || $parent instanceof PropertyFetchNode) && $parent->object !== $node && self::isNullsafe($parent))
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether a nullsafe operator in the chain may skip the access. */
	private static function isNullsafe(ExpressionNode $chain): bool
	{
		for ($node = $chain; $node instanceof MethodCallNode || $node instanceof PropertyFetchNode; $node = $node->object) {
			if ($node->nullsafe) {
				return true;
			}
		}

		return false;
	}


	/**
	 * The function whose variable the variable is: a variable of the `use` of a closure belongs to the function
	 * around it, and one of an arrow function that is no parameter of it too.
	 */
	private static function findScope(VariableNode $variable): ?FunctionLikeNode
	{
		$scope = $variable->findAncestor(FunctionLikeNode::class);
		if ($variable->parent instanceof ClosureUseNode) {
			$scope = $scope?->findAncestor(FunctionLikeNode::class);
		}

		while (
			$scope instanceof ArrowFunctionNode
			&& !array_any($scope->parameters->getItems(), fn(ParameterNode $parameter) => $parameter->variable->plainName === $variable->plainName)
		) {
			$scope = $scope->findAncestor(FunctionLikeNode::class);
		}

		return $scope;
	}


	/**
	 * Whether the closure stands in a constant expression, the value of a constant, an enum case, a property or a
	 * parameter or the argument of an attribute.
	 */
	private static function standsInConstantExpression(ClosureNode $closure): bool
	{
		for ($node = $closure->parent; $node !== null; $node = $node->parent) {
			if (
				$node instanceof ConstItemNode
				|| $node instanceof AttributeNode
				|| $node instanceof ParameterNode
				|| $node instanceof PropertyItemNode
				|| $node instanceof EnumCaseNode
			) {
				return true;
			} elseif ($node instanceof StatementNode || $node instanceof FunctionLikeNode) {
				return false;
			}
		}

		return false;
	}
}
