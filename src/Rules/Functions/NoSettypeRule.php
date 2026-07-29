<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{GlobalCalls, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, CatchNode, ClosureUseNode, FunctionLikeNode, ParameterNode, PlainNodeList, StatementNode, StaticVariableNode};
use PhpSyntax\Nodes\Expression\{AssignmentByReferenceNode, AssignmentNode, ClosureNode, FunctionCallNode, VariableNode};
use PhpSyntax\Nodes\Statement\{DoWhileNode, ExpressionStatementNode, ForeachNode, ForNode, GlobalNode, StaticNode, UnsetNode, WhileNode};
use function array_key_exists, count, is_string;


/**
 * An assignment with a cast instead of a `settype()` statement: `$a = (int) $a;`, not `settype($a, 'int');`.
 * The cast reads the variable, which warns where it is undefined and `settype()` does not, so the call stays in
 * a scope that reaches variables by names it does not spell out, where a variable may exist only at run time, and
 * elsewhere the fix is risky unless the variable is evidently defined before the call: a parameter, a variable of
 * the `use` of a closure, one an enclosing `foreach` or `catch` binds, or one a statement before the call in a block
 * enclosing it assigns, destructures or declares `global` or `static`, and no `unset()` of it may run in between;
 * `settype($x, 'null')` reads nothing. It stays
 * on `$this` and `$GLOBALS` too, which cannot be assigned.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoSettypeRule extends NodeRule
{
	private const Casts = [
		'int' => 'int',
		'integer' => 'int',
		'bool' => 'bool',
		'boolean' => 'bool',
		'float' => 'float',
		'double' => 'float',
		'string' => 'string',
		'array' => 'array',
		'object' => 'object',
		'null' => null,
	];


	public static function getDecisions(): array
	{
		return [new Decision('cleanup.settype', Domain::state('forbidden'), '`settype($x, \'int\')` as a statement is `$x = (int) $x`')];
	}


	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ExpressionStatementNode || !($call = $node->expression) instanceof FunctionCallNode) {
			return;
		}

		$args = $call->arguments->items->getItems();
		[$var, $type] = [$args[0] ?? null, $args[1] ?? null];
		if (
			count($args) !== 2
			|| !$var instanceof ArgumentNode
			|| !$type instanceof ArgumentNode
			|| $var->name !== null || $var->ampersand !== null || $var->ellipsis !== null
			|| $type->name !== null || $type->ampersand !== null || $type->ellipsis !== null
			|| !$var->value instanceof VariableNode
			|| !$var->value->name instanceof Token
			|| $var->value->dollar || $var->value->openBrace
			|| $var->value->isThis()
			|| $var->value->name->text === '$GLOBALS'
			|| !$type->value->hasValue()
			|| !is_string($typeName = $type->value->toValue())
			|| !array_key_exists($cast = strtolower($typeName), self::Casts)
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'settype')
			|| $call->hasInnerComment()
			|| self::hasDynamicVariables($call, $context)
		) {
			return;
		}

		$cast = self::Casts[$cast];
		$name = $var->value->name->text;
		[$risk, $because] = match (true) {
			$cast !== null && !self::isDefinedBefore($var->value, $node) => [Risk::BehaviorChanges, 'the variable may be undefined, which the cast warns about'],
			($uncertainty = GlobalCalls::findUncertainty($call, $context)) !== null => [Risk::NameUncertain, $uncertainty],
			default => [null, null],
		};
		if (!$context->report($call, 'The `settype()` call must be written as an assignment of a cast.', risk: $risk, because: $because)) {
			return;
		}

		$call->replaceWith((new Builder)->expression($cast === null ? "$name = null" : "$name = ($cast) $name"));
	}


	/**
	 * Whether the variable is evidently defined when the statement runs: a parameter, a variable a closure uses, one
	 * an enclosing `foreach` or `catch` binds, or one a statement before it in an enclosing block assigns, declares
	 * `global` or `static`.
	 */
	private static function isDefinedBefore(VariableNode $variable, StatementNode $statement): bool
	{
		$name = $variable->plainName;
		$function = $statement->findAncestor(FunctionLikeNode::class);
		$scope = $function ?? $statement->getFile();
		if ($scope !== null && self::mayBeUnset($name, $statement, $scope)) {
			return false;
		} elseif (
			array_any($function?->parameters?->getItems() ?? [], fn(ParameterNode $parameter) => $parameter->variable->plainName === $name)
			|| array_any(
				$function instanceof ClosureNode ? $function->uses?->items->getItems() ?? [] : [],
				fn(ClosureUseNode $use) => $use->variable->plainName === $name,
			)
		) {
			return true;
		}

		for ($node = $statement; ($parent = $node->parent) !== null && $parent !== $function; $node = $parent) {
			$binds = match (true) {
				$parent instanceof ForeachNode => $parent->findSlotOf($node) !== 'expression'
					&& array_any([$parent->key, $parent->value], fn(?Node $bound) => $bound !== null && self::writes($bound, $name)),
				$parent instanceof CatchNode => $parent->variable?->plainName === $name,
				$parent instanceof PlainNodeList => array_any(
					array_slice($parent->getItems(), 0, (int) array_search($node, $parent->getItems(), true)),
					fn(Node $before) => self::defines($before, $name),
				),
				default => false,
			};
			if ($binds) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Whether an `unset()` of the variable in the scope may run before the statement: one standing before it, or one
	 * in a loop enclosing the statement too, which runs before it from the second iteration on.
	 */
	private static function mayBeUnset(?string $name, StatementNode $statement, Node $scope): bool
	{
		$first = $statement->getFirstToken();
		foreach ($scope->find(UnsetNode::class) as $unset) {
			if (
				$unset->findAncestor(FunctionLikeNode::class) !== ($scope instanceof FunctionLikeNode ? $scope : null)
				|| !array_any($unset->variables->getItems(), fn(Node $item) => $item instanceof VariableNode && $item->plainName === $name)
			) {
				continue;
			}

			for ($token = $unset->getLastToken(); $token !== null; $token = $token->getNext()) {
				if ($token === $first) {
					return true;
				}
			}

			for ($loop = $statement->parent; $loop !== null && $loop !== $scope; $loop = $loop->parent) {
				if (
					($loop instanceof ForNode || $loop instanceof ForeachNode || $loop instanceof WhileNode || $loop instanceof DoWhileNode)
					&& in_array($unset, $loop->find(UnsetNode::class), true)
				) {
					return true;
				}
			}
		}

		return false;
	}


	/** Whether the statement defines the variable whenever it runs to its end. */
	private static function defines(Node $statement, ?string $name): bool
	{
		$expression = $statement instanceof ExpressionStatementNode ? $statement->expression : null;
		while ($expression instanceof AssignmentNode || $expression instanceof AssignmentByReferenceNode) {
			if (self::writes($expression->target, $name)) {
				return true;
			}
			$expression = $expression->expression;
		}

		return match (true) {
			$statement instanceof GlobalNode => array_any($statement->variables->getItems(), fn(VariableNode $global) => $global->plainName === $name),
			$statement instanceof StaticNode => array_any($statement->variables->getItems(), fn(StaticVariableNode $static) => $static->variable->plainName === $name),
			default => false,
		};
	}


	/** Whether the target of a write, a variable or a destructuring, writes the variable. */
	private static function writes(Node $target, ?string $name): bool
	{
		return array_any(
			[$target, ...$target->find(VariableNode::class)],
			fn(Node $node) => $node instanceof VariableNode && $node->plainName === $name && $node->isWritten(),
		);
	}


	/** Whether the function or the file the node stands in reaches a variable by a name it does not spell out. */
	private static function hasDynamicVariables(Node $node, RuleContext $context): bool
	{
		$function = $node->findAncestor(FunctionLikeNode::class);
		return array_any(
			NodeHelpers::findDynamicVariableAccesses($function ?? $context->file, $context),
			fn(Node $access) => $access->findAncestor(FunctionLikeNode::class) === $function,
		);
	}
}
