<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\{ArgumentListNode, Expression, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Scalar\NullNode;
use function count;


/**
 * `json_decode($json) !== null && json_last_error() === JSON_ERROR_NONE` asks whether the text is JSON and
 * throws the value away, which is what `json_validate()` of PHP 8.3 is for. The `associative` argument goes
 * with the decoding; a call carrying `depth` or `flags` stays: the new function takes only one of the flags
 * of the old one, and the fix carries over nothing but the text.
 *
 * Every fix is risky, and for one document: `json_decode('null')` gives null without an error, so the pair
 * says the text is not JSON, while `json_validate('null')` says it is.
 */
#[RuleInfo(
	'dresscode/json-validate-for-decode-test',
	Stage::Structure,
	description: 'Replaces json_decode() called only to test the input with json_validate()',
	group: Group::Modernization,
	requires: ['php' => '>=8.3'],
	risky: true,
)]
final class JsonValidateForDecodeTestRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\BinaryOpNode
			|| !$node->operator->is(TokenKind::BooleanAnd)
			|| $node->hasComment()
			|| !$this->isErrorCheck($node->right, $context)
		) {
			return;
		}

		// the two checks are the whole condition, or its end, which && binds to the left
		$chained = $node->left instanceof Expression\BinaryOpNode && $node->left->operator->is(TokenKind::BooleanAnd)
			? $node->left
			: null;
		$decode = $this->readDecodedJson($chained === null ? $node->left : $chained->right, $context);
		if (
			$decode === null
			|| !$context->report($node, 'The test for JSON must be written with json_validate()')
		) {
			return;
		}

		assert($decode->name instanceof NameNode);
		$spelling = NodeHelpers::spellGlobalFunction('json_validate', $decode->name, $context);
		$json = $decode->arguments->findArgument('json', 0)?->value;
		assert($json !== null);
		$call = Expression\FunctionCallNode::of(NameNode::fromText($spelling), ArgumentListNode::of($json->withoutEdgeTrivia()));

		if ($chained === null) {
			$node->replaceWith($call);
			return;
		}

		$node->replaceWith(Expression\BinaryOpNode::of($chained->left->withoutEdgeTrivia(), '&&', $call));
	}


	/** The call of `json_decode()` whose result the expression only compares with null, the value thrown away. */
	private function readDecodedJson(ExpressionNode $expression, RuleContext $context): ?Expression\FunctionCallNode
	{
		if (
			!$expression instanceof Expression\BinaryOpNode
			|| !$expression->operator->is(TokenKind::IsNotIdentical)
		) {
			return null;
		}

		$call = match (true) {
			$expression->right instanceof NullNode => $expression->left,
			$expression->left instanceof NullNode => $expression->right,
			default => null,
		};
		if (
			!$call instanceof Expression\FunctionCallNode
			|| !$call->name instanceof NameNode
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'json_decode')
			|| $call->arguments->isPartialApplication()
			|| $call->arguments->findArgument('json', 0) === null
			|| $call->arguments->findArgument('depth', 2) !== null
			|| $call->arguments->findArgument('flags', 3) !== null
			|| count($call->arguments->items) > 2
		) {
			return null;
		}

		return $call;
	}


	/** Whether the expression is `json_last_error() === JSON_ERROR_NONE`, which says the decoding went through. */
	private function isErrorCheck(ExpressionNode $expression, RuleContext $context): bool
	{
		if (
			!$expression instanceof Expression\BinaryOpNode
			|| !$expression->operator->is(TokenKind::IsIdentical)
		) {
			return false;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		[$call, $constant] = $expression->right instanceof Expression\ConstantFetchNode
			? [$expression->left, $expression->right]
			: [$expression->right, $expression->left];
		return $call instanceof Expression\FunctionCallNode
			&& count($call->arguments->items) === 0
			&& $resolver->isGlobalFunctionCall($call, 'json_last_error')
			&& $constant instanceof Expression\ConstantFetchNode
			&& $resolver->resolveConstant($constant->name) === 'JSON_ERROR_NONE';
	}
}
