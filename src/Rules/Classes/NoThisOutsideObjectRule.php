<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\Scope;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousFunctionNode, FunctionLikeNode, SeparatedNodeList};
use PhpSyntax\Nodes\Expression\{EmptyNode, IssetNode, VariableNode};


/**
 * `$this` in a static method, a static closure or a plain function fails at runtime, unless `isset()` or `empty()`
 * asks for it; reported. The main code of a file has no function around it and is left alone.
 */
#[RuleInfo(Stage::Structure, analyses: [Scope::class])]
final class NoThisOutsideObjectRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.thisOutsideObject', Domain::state('forbidden'), '`$this` in a static method, a static closure or a function outside a class')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof VariableNode
			|| !$node->name instanceof Token
			|| $node->name->text !== '$this'
			|| $node->dollar !== null
		) {
			return;
		}

		$scope = $context->getAnalysis(Scope::class);
		$function = $node->findAncestor(FunctionLikeNode::class);
		if ($function === null || $scope->hasThis($node) || self::isTested($node)) {
			return;
		}

		// a closure that is not static can be bound to an object later, whoever wrote it and where,
		// so its $this is a promise, not a mistake; only a static one can never receive an object
		$bindable = $function instanceof AnonymousFunctionNode
			&& $function->staticKeyword === null;
		if (!$bindable) {
			$context->report($node, 'The `$this` stands in a static context, which has no object.', fixable: false);
		}
	}


	/** Whether `isset($this)` or `empty($this)` asks whether there is an object, which works anywhere. */
	private static function isTested(VariableNode $node): bool
	{
		$parent = $node->parent;
		return ($parent instanceof EmptyNode && $parent->expression === $node)
			|| ($parent instanceof SeparatedNodeList && $parent->parent instanceof IssetNode);
	}
}
