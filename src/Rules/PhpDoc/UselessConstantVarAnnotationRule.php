<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, VarTagValueNode};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\ClassConstNode;
use function count;


/**
 * No `@var` on a class constant, whose type is always clear from its value; a tag with a description stays,
 * and so does a type that is more than a bare name (`int[]`, `array<string, Foo>`, `?int`).
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true, analyses: [PhpDoc::class])]
final class UselessConstantVarAnnotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('phpdoc.constantVar', Domain::state('forbidden'), 'A `@var` on a class constant that says nothing')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassConstNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstNode
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = array_filter(
			$tree->children,
			fn($child) => !$child instanceof PhpDocTagNode
				|| !$child->value instanceof VarTagValueNode
				|| !$child->value->type instanceof IdentifierTypeNode
				|| $child->value->description !== ''
				|| $child->value->variableName !== '',
		);
		if (
			count($kept) === count($tree->children)
			|| !$context->report($node, 'Useless `@var` annotation on a constant, because its value gives the type.', trivia: $docComment)
		) {
			return;
		}

		$tree->children = array_values($kept);
		$phpDoc->writeBack($tree, $docComment, $node);
	}
}
