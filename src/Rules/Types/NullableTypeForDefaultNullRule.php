<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Type\{NamedTypeNode, NullableTypeNode};
use function in_array;


/**
 * An explicit `?` on the type of a parameter whose default is `null`, instead of the deprecated
 * implicit nullability; a union or intersection type is left alone, and so are `mixed` and `null`, which hold null
 * already and take no `?`.
 */
#[RuleInfo(
	'dresscode/nullable-type-for-default-null',
	Stage::Structure,
	description: 'Marks the type of a parameter defaulting to null as nullable',
	group: Group::Deprecations,
)]
final class NullableTypeForDefaultNullRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| !($type = $node->type) instanceof NamedTypeNode
			|| in_array(strtolower($type->name->token->text), ['mixed', 'null'], true)
			|| !$node->default instanceof NullNode
			|| !$context->report($type, 'The type of a parameter defaulting to null must be nullable')
		) {
			return;
		}

		$nullable = (new Parser)->parseType('?int');
		assert($nullable instanceof NullableTypeNode);
		$inner = clone $type;
		$nullable->question->setLeadingTrivia($inner->name->token->leadingTrivia);
		$inner->name->token->setLeadingTrivia([]);
		$nullable->type = $inner;
		$node->type = $nullable;
	}
}
