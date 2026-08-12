<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, UnqualifiedResolution};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\{ExpressionNode, NameNode};
use function array_key_exists;


/**
 * What a rule asks about a call of a global function: which function it calls, and why that may be another one.
 */
final class GlobalCalls
{
	/**
	 * The global function among the names the call calls, lowercased; null for a call of any other function. The name is
	 * resolved only for a call written with one of the names, or in a file that imports a function under another name.
	 * @param array<lowercase-string, mixed> $names  the lowercased names of global functions as keys, the values not read,
	 *                                               so that a rule passes the map it keeps its data in
	 */
	public static function findFunction(FunctionCallNode $call, array $names, RuleContext $context): ?string
	{
		$name = $call->name;
		return !$name instanceof NameNode
			|| (!array_key_exists(strtolower($name->shortName), $names) && !CodeWriter::importsFunctionAs($context))
				? null
				: $context->getAnalysis(NameResolver::class)->findGlobalFunction($call, $names);
	}


	/**
	 * Why a call taken as a call of a global function may call another one: its name is unqualified in a namespace
	 * that may declare a function of that name elsewhere (`UnqualifiedResolution::Uncertain`). A rule rewriting such a call
	 * reports it with `Risk::NameUncertain` and this as the reason; null when the call is certain.
	 */
	public static function findUncertainty(FunctionCallNode $call, RuleContext $context): ?string
	{
		$name = $call->name;
		return $name instanceof NameNode
			&& $name->form === NameForm::Unqualified
			&& $context->getAnalysis(NameResolver::class)->getUnqualifiedResolution($name) === UnqualifiedResolution::Uncertain
				? 'the namespace may declare `' . strtolower($name->text) . '()`'
				: null;
	}


	/**
	 * Why a rewrite of the expression that keeps the expressions given may change what the code calls: the uncertainty
	 * of the first call it takes away, the expression itself and every call in it but those inside what it keeps, as
	 * `findUncertainty()` gives it; null when every such call is certain.
	 * @param  list<ExpressionNode>  $kept
	 */
	public static function findUncertaintyOfRewrite(ExpressionNode $expression, array $kept, RuleContext $context): ?string
	{
		foreach ([$expression, ...$expression->find(FunctionCallNode::class)] as $call) {
			if (
				$call instanceof FunctionCallNode
				&& !self::isKept($call, $kept, $expression)
				&& ($uncertainty = self::findUncertainty($call, $context)) !== null
			) {
				return $uncertainty;
			}
		}

		return null;
	}


	/**
	 * Whether the node stands in one of the kept expressions, below the expression rewritten.
	 * @param  list<ExpressionNode>  $kept
	 */
	private static function isKept(Node $node, array $kept, ExpressionNode $expression): bool
	{
		for (; $node !== null && $node !== $expression; $node = $node->parent) {
			if (in_array($node, $kept, true)) {
				return true;
			}
		}

		return false;
	}
}
