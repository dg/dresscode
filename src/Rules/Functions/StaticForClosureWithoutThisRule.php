<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousFunctionNode, ArgumentNode, Expression, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode};
use PhpSyntax\Nodes\Statement\FunctionNode;
use function in_array;


/**
 * A closure or arrow function that does not use `$this` is declared static, so that it holds no reference
 * to the object and cannot be bound to one by mistake. `parent::` and code reaching a variable by a name it
 * does not spell out (`$$name`, `compact()`, `extract()`, `get_defined_vars()`, `eval`, `include`) may hide
 * `$this`, such a closure stays; so does one bound right away with `bindTo()`, `call()` or
 * `Closure::bind()`. A closure bound elsewhere is out of sight of the rule, so every fix waits for the run to
 * allow it.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class StaticForClosureWithoutThisRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('functions.staticWithoutThis.closure', new Words(['required' => 'declared `static`']), 'The `static` keyword of a closure or an arrow function that does not use `$this`')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\ClosureNode::class, Expression\ArrowFunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof Expression\ClosureNode && !$node instanceof Expression\ArrowFunctionNode)
			|| $node->staticKeyword !== null
			|| self::mayUseThis($node, $context)
			|| self::isBound($node, $context)
		) {
			return;
		}

		$keyword = $node instanceof Expression\ClosureNode ? $node->functionKeyword : $node->fnKeyword;
		if (!$context->report($keyword, 'The closure not using `$this` must be static.', risk: Risk::BehaviorChanges, because: 'a closure bound to an object elsewhere is not seen')) {
			return;
		}

		$static = Token::fromText('static')
			->setLeadingTrivia($keyword->leadingTrivia)
			->setTrailingTrivia([Trivia::fromText(' ')]);
		$keyword->setLeadingTrivia([]);
		$node->staticKeyword = $static;
	}


	private static function mayUseThis(
		Expression\ClosureNode|Expression\ArrowFunctionNode $closure,
		RuleContext $context,
	): bool
	{
		if (NodeHelpers::findDynamicVariableAccesses($closure, $context) !== []) {
			return true;
		}

		foreach ($closure->find(Node::class) as $node) {
			$class = match (true) {
				$node instanceof Expression\StaticMethodCallNode, $node instanceof Expression\StaticPropertyFetchNode,
				$node instanceof Expression\ClassConstantFetchNode, $node instanceof Expression\NewNode => $node->class,
				default => null,
			};
			if (
				(
					$node instanceof Expression\VariableNode
					&& $node->isThis()
					&& self::belongsTo($node, $closure)
				)
				|| (
					$class instanceof NameNode
					&& $class->equals('parent')
					&& self::belongsTo($node, $closure)
				)
			) {
				return true;
			}
		}

		return false;
	}


	/** Whether `$this` at the node is the one of the closure: no function, method or static closure in between. */
	private static function belongsTo(Node $node, Expression\ClosureNode|Expression\ArrowFunctionNode $closure): bool
	{
		for ($ancestor = $node->parent; $ancestor !== null && $ancestor !== $closure; $ancestor = $ancestor->parent) {
			if (
				$ancestor instanceof FunctionNode
				|| $ancestor instanceof MethodNode
				|| $ancestor instanceof PropertyHookNode
				|| ($ancestor instanceof AnonymousFunctionNode && $ancestor->staticKeyword !== null)
			) {
				return false;
			}
		}

		return true;
	}


	/** `(function () {})->bindTo($o)`, `(fn() => 1)->call($o)` and `Closure::bind(function () {}, $o)`. */
	private static function isBound(Expression\ClosureNode|Expression\ArrowFunctionNode $closure, RuleContext $context): bool
	{
		$parent = $closure->parent;
		if ($parent instanceof Expression\ParenthesizedNode) {
			$call = $parent->parent;
			return $call instanceof Expression\MethodCallNode
				&& $call->object === $parent
				&& $call->name instanceof IdentifierNode
				&& in_array(strtolower($call->name->token->text), ['bindto', 'call'], true);
		}

		$call = $parent instanceof ArgumentNode ? $parent->parent?->parent?->parent : null;
		return $call instanceof Expression\StaticMethodCallNode
			&& $call->class instanceof NameNode
			&& strcasecmp($context->getAnalysis(NameResolver::class)->resolveClass($call->class), 'Closure') === 0
			&& $call->name instanceof IdentifierNode
			&& strtolower($call->name->token->text) === 'bind'
			&& ($call->arguments->items->getItems()[0] ?? null) === $parent;
	}
}
