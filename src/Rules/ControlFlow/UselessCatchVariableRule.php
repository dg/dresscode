<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CatchNode, FunctionLikeNode, Statement};
use PhpSyntax\Nodes\Expression\VariableNode;


/**
 * A catch keeps no variable it never reads: `catch (Exception $e)` becomes `catch (Exception)`. The variable
 * outlives the block, so it counts as read anywhere in the enclosing function, in nested closures included.
 * Code reaching a variable by a name it does not spell out (`$$name`, `compact()`, `extract()`,
 * `get_defined_vars()`, `eval`, `include`) keeps it too wherever it may run after the catch: written after it,
 * in a loop around it, or in a scope that jumps with goto.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'], analyses: [NameResolver::class])]
final class UselessCatchVariableRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('cleanup.catchWithoutVariable', Domain::state('required'), 'A `catch (E)` without a variable where the variable would never be read')];
	}


	public function getVisitedNodes(): array
	{
		return [CatchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof CatchNode
			|| $node->variable === null
			|| !$node->variable->name instanceof Token
		) {
			return;
		}

		$name = $node->variable->name->text;
		$scope = $node->findAncestor(FunctionLikeNode::class) ?? $context->file;
		foreach (NodeHelpers::findDynamicVariableAccesses($scope, $context) as $access) {
			if (self::mayRunAfter($access, $node, $scope)) {
				return;
			}
		}

		foreach ($scope->find(VariableNode::class) as $var) {
			if ($var->name instanceof Token && $var->name->text === $name && !$var->parent instanceof CatchNode) {
				return;
			}
		}

		if (!$context->report($node->variable, "Useless catch variable `$name`, because nothing reads it.")) {
			return;
		}

		$previous = $node->variable->getFirstToken()->getPrevious();
		if ($previous?->getTrailingSpace() !== null) {
			$previous->setTrailingSpace('');
		}

		$node->variable = null;
	}


	/**
	 * Whether the access runs in the scope of the catch and may run after it: it is written after the catch or in
	 * a loop around it, or the scope jumps with goto.
	 */
	private static function mayRunAfter(Node $access, CatchNode $catch, Node $scope): bool
	{
		$function = $catch->findAncestor(FunctionLikeNode::class);
		if ($access->findAncestor(FunctionLikeNode::class) !== $function) {
			return false;
		}

		$accessToken = $access->getFirstToken();
		$catchToken = $catch->getFirstToken();
		if (
			$accessToken === null
			|| $catchToken->isBefore($accessToken)
		) {
			return true;
		}

		$loops = [];
		for ($ancestor = $catch->parent; $ancestor !== null && $ancestor !== $scope; $ancestor = $ancestor->parent) {
			if (
				$ancestor instanceof Statement\ForNode
				|| $ancestor instanceof Statement\ForeachNode
				|| $ancestor instanceof Statement\WhileNode
				|| $ancestor instanceof Statement\DoWhileNode
			) {
				$loops[] = $ancestor;
			}
		}

		for ($ancestor = $access->parent; $ancestor !== null && $ancestor !== $scope; $ancestor = $ancestor->parent) {
			if (in_array($ancestor, $loops, true)) {
				return true;
			}
		}

		return $scope->findFirst(Statement\GotoNode::class, fn(Statement\GotoNode $goto) => $goto->findAncestor(FunctionLikeNode::class) === $function) !== null;
	}
}
