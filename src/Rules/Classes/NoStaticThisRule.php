<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\Analyses\Scope;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode, VariableNode};
use PhpSyntax\Nodes\FunctionLikeNode;


/**
 * `$this` in a static method, a static closure or a plain function fails at runtime; reported.
 */
#[RuleInfo(
	'dresscode/noStaticThis',
	Stage::Structure,
	description: 'Reports `$this` used where no object is available',
	group: RuleGroup::Correctness,
)]
final class NoStaticThisRule extends NodeRule
{
	public function getVisitedTypes(): array
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
		if ($function === null || $scope->hasThis($node)) {
			return;
		}

		// a closure that is not static can be bound to an object later, whoever wrote it and where,
		// so its $this is a promise, not a mistake; only a static one can never receive an object
		$bindable = ($function instanceof ClosureNode || $function instanceof ArrowFunctionNode)
			&& $function->staticKeyword === null;
		if (!$bindable) {
			$context->report($node, '`$this` is not available in a static context', fixable: false);
		}
	}
}
