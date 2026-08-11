<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NamedTypeNode, UnionTypeNode};
use function in_array;


/**
 * An explicit `?` on the type of a parameter whose default is `null`, `|null` on a union type and on an intersection
 * one, which takes the parentheses PHP 8.2 gave it along with the null default, instead of the deprecated implicit
 * nullability; `mixed` and `null`, which hold null already, are left alone.
 */
#[RuleInfo(Stage::Structure)]
final class NullableTypeForDefaultNullRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.implicitNullable', Domain::state('forbidden'), 'A parameter type made nullable by its default `null` alone, `int $x = null`, deprecated by PHP 8.4 and written `?int $x = null`')];
	}


	public function getVisitedNodes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| !$node->default instanceof NullNode
			|| ($type = $node->type) === null
		) {
			return;
		}

		$nullable = match (true) {
			$type instanceof NamedTypeNode => self::holdsNull($type) ? null : '?' . $type->text,
			$type instanceof UnionTypeNode => array_any($type->types->getItems(), fn(Node $item) => $item instanceof NamedTypeNode && self::holdsNull($item))
				? null
				: $type->text . (preg_match('~\s*\|\s*~', $type->text, $m) ? $m[0] : '|') . 'null', // spaced as the union is
			$type instanceof IntersectionTypeNode => "($type->text)|null",
			default => null,
		};
		if (
			$nullable !== null
			&& $context->report($type, "The type of the parameter `\${$node->variable->plainName}` defaulting to `null` must be written `$nullable`.")
		) {
			$node->setType((new Builder)->type($nullable));
		}
	}


	private static function holdsNull(NamedTypeNode $type): bool
	{
		return in_array(strtolower($type->name->token->text), ['mixed', 'null'], true);
	}
}
