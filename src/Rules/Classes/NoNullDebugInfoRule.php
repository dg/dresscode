<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, FunctionLikeNode};
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, ReturnNode};
use function in_array;


/**
 * `__debugInfo()` tells a dump what to show of the object, and null tells it nothing, which is what an empty
 * array says without the deprecation PHP 8.5 put on it. A bare `return` says null as well and is read the
 * same way; a return standing in a closure inside the method belongs to that closure and stays. Only a null
 * written out is rewritten: without the types, an expression giving null is not told from one giving an array.
 * The nullable return type, which PHP 8.6 deprecated as well, becomes `array`.
 */
#[RuleInfo(Stage::Structure)]
final class NoNullDebugInfoRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.__debugInfo', new Words(['array' => 'an empty array where `null` is returned']), 'The `null` that `__debugInfo()` returns, which becomes `[]`')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| !$node->name->equals('__debugInfo')
			|| $node->body === null
		) {
			return;
		}

		foreach ($node->find(ReturnNode::class) as $return) {
			if (
				($return->expression !== null && !$return->expression instanceof NullNode)
				|| $return->findAncestor(FunctionLikeNode::class) !== $node
				|| $return->hasInnerComment()
				|| !$context->report($return, 'The `__debugInfo()` method must return an array, not `null`.')
			) {
				continue;
			}

			if ($return->expression === null) {
				$return->replaceWith((new Builder)->fragment(ReturnNode::class, 'return [];'));
			} else {
				$return->expression->replaceWith((new Builder)->value([]));
			}
		}

		$this->narrowReturnType($node, $context);
	}


	/**
	 * The nullable return type, which PHP 8.6 deprecated, written as `array`; a null still returned keeps it, and an
	 * expression returned makes the fix risky, being null for all the rule knows. Only a class no other can extend
	 * gets the fix: a child declaring `?array` would then be incompatible with it.
	 */
	private function narrowReturnType(MethodNode $node, RuleContext $context): void
	{
		$type = $node->returnType;
		if (
			$type === null
			|| $type->hasInnerComment()
			|| !in_array(strtolower((string) preg_replace('~\s+~', '', $type->text)), ['?array', 'array|null', 'null|array'], true)
		) {
			return;
		}

		$risky = false;
		foreach ($node->find(ReturnNode::class) as $return) {
			if ($return->findAncestor(FunctionLikeNode::class) !== $node) {
				continue;
			} elseif ($return->expression === null || $return->expression instanceof NullNode) {
				return;
			}

			$risky = $risky || !$return->expression instanceof ArrayNode;
		}

		$owner = $node->findAncestor(ClassLikeNode::class);
		$message = 'The return type of `__debugInfo()` must be `array`, not a nullable one.';
		if (
			!$owner instanceof AnonymousClassNode
			&& !$owner instanceof EnumNode
			&& !($owner instanceof ClassNode && $owner->modifiers->final)
		) {
			$context->report($type, $message, fixable: false);
		} elseif ($context->report(
			$type,
			$message,
			risk: $risky ? Risk::TypeUnknown : null,
			because: $risky ? 'a returned expression may be null' : null,
		)) {
			$node->setReturnType((new Builder)->type('array'));
		}
	}
}
