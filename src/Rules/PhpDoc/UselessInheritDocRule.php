<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ClassLikeNode, TypeNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Type\{NamedTypeNode, NullableTypeNode};
use function in_array;


/**
 * A doc comment saying nothing but `{@inheritDoc}` is removed: the tools that read doc comments inherit the
 * documentation of the parent anyway. It stays on a function whose signature does not say everything: a missing
 * type, or an array or iterable type whose items the parent documents.
 */
#[RuleInfo(
	'dresscode/useless-inheritdoc',
	Stage::Structure,
	description: 'Removes a doc comment consisting of @inheritDoc only',
	group: Group::Cleanup,
	modifiesComments: true,
)]
final class UselessInheritDocRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class, FunctionNode::class, PropertyNode::class, ClassConstNode::class, ClassLikeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof Token
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
			|| !preg_match('~^(?:\{@inheritDoc\}|@inheritDoc)$~i', trim(substr($docComment->text, 3, -2), " \t\r\n*"))
		) {
			return;
		}

		if ($node instanceof MethodNode || $node instanceof FunctionNode) {
			$types = [$node->returnType];
			foreach ($node->parameters->getItems() as $param) {
				$types[] = $param->type;
			}

			foreach ($types as $type) {
				if ($type === null || self::isIterable($type)) {
					return;
				}
			}
		}

		if ($context->report($node, 'Useless doc comment with @inheritDoc', trivia: $docComment)) {
			$node->removeDocComment();
		}
	}


	private static function isIterable(TypeNode $type): bool
	{
		$type = $type instanceof NullableTypeNode ? $type->type : $type;
		return $type instanceof NamedTypeNode
			&& in_array(strtolower($type->name->text), ['array', 'iterable'], true);
	}
}
