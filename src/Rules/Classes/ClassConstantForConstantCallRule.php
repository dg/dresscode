<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression, ExpressionNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use function count;


/**
 * `constant(Example::class . '::' . $name)` builds the name of a class constant out of pieces and asks for it
 * by string; PHP 8.3 fetches it directly, `Example::{$name}`. The class must be written as `X::class`: without
 * the types, a variable holding the name of a class is not told from one holding an object, which the call
 * turns into a string first. The name of the constant may be any expression.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.3'], analyses: [NameResolver::class])]
final class ClassConstantForConstantCallRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.dynamicClassConstant', Domain::adopted(), '`X::{$name}` for `constant("X::$name")`')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
			return;
		}

		$argument = $node->arguments->items->getItems()[0] ?? null;
		if (
			count($node->arguments->items) !== 1
			|| !$argument instanceof ArgumentNode
			|| $argument->name !== null || $argument->ampersand !== null || $argument->ellipsis !== null
			|| GlobalCalls::findFunction($node, ['constant' => true], $context) === null
		) {
			return;
		}

		$concat = $argument->value;
		$prefix = $concat instanceof Expression\BinaryOpNode && $concat->operator->is('.') ? $concat->left : null;
		if (
			$prefix === null
			|| !$prefix instanceof Expression\BinaryOpNode
			|| !$prefix->operator->is('.')
			|| !self::namesClass($prefix->left)
			|| !$prefix->right instanceof StringNode
			|| $prefix->right->toValue() !== '::'
			|| $node->hasInnerComment()
		) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertainty($node, $context);
		if (!$context->report(
			$node,
			'The `constant()` call must be written `' . $prefix->left->class->text . '::{…}`.',
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		$node->replaceWith((new Builder)->classConstantFetch($prefix->left->class->text, $concat->right->withoutEdgeTrivia()));
	}


	/**
	 * Whether the expression is `X::class`, the only form that says a class name is what it holds.
	 * @phpstan-assert-if-true Expression\ClassConstantFetchNode $expression
	 */
	private static function namesClass(ExpressionNode $expression): bool
	{
		return $expression instanceof Expression\ClassConstantFetchNode
			&& $expression->class instanceof NameNode
			&& $expression->name instanceof IdentifierNode
			&& $expression->name->equals('class');
	}
}
