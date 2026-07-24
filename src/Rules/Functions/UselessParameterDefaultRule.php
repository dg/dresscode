<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ParameterNode, SeparatedNodeList};
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Type\{NamedTypeNode, NullableTypeNode, UnionTypeNode};
use function in_array;


/**
 * No default value on a parameter that a required one follows; such a default can never apply.
 * A default `null` on a type that does not hold null itself stays, because it is what makes the type
 * nullable, and a promoted property is not touched at all, though it may be the required one.
 */
#[RuleInfo(Stage::Structure)]
final class UselessParameterDefaultRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('functions.uselessParameterDefault', Domain::state('forbidden'), 'A default a required parameter after it makes unreachable')];
	}


	public function getVisitedNodes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| $node->equals === null
			|| $node->default === null
			|| !$node->modifiers->isEmpty()
			|| self::keepsImplicitNullability($node)
			|| !($params = $node->parent) instanceof SeparatedNodeList
			|| !self::hasRequiredAfter($params, $node)
			|| $node->equals->hasComment()
			|| $node->equals->hasCommentUpTo($node->getLastToken())
			|| $node->getLastToken()->hasComment()
			|| !$context->report($node->default, "Useless default value of `\${$node->variable->plainName}`, because a required parameter follows.")
		) {
			return;
		}

		$node->equals = null;
		$node->default = null;
		$node->variable->getLastToken()->removeTrailingWhitespace();
	}


	/** Removing `= null` from a type that does not hold null itself would stop the parameter accepting null. */
	private static function keepsImplicitNullability(ParameterNode $node): bool
	{
		$type = $node->type;
		return $type !== null
			&& $node->default instanceof NullNode
			&& !$type instanceof NullableTypeNode
			&& !array_any(
				$type instanceof UnionTypeNode ? $type->types->getItems() : [$type],
				fn(Node $item) => $item instanceof NamedTypeNode && in_array(strtolower($item->name->token->text), ['mixed', 'null'], true),
			);
	}


	/** @param  SeparatedNodeList<ParameterNode>  $params */
	private static function hasRequiredAfter(SeparatedNodeList $params, ParameterNode $node): bool
	{
		$seen = false;
		foreach ($params->getItems() as $param) {
			if ($param === $node) {
				$seen = true;
			} elseif (
				$seen
				&& $param->equals === null
				&& $param->ellipsis === null
			) {
				return true;
			}
		}

		return false;
	}
}
