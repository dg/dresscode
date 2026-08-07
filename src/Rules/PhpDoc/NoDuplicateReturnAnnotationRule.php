<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * A function documents its return value once; a second `@return` is reported.
 */
#[RuleInfo(
	'dresscode/no-duplicate-return-annotation',
	Stage::Structure,
	description: 'Reports more than one @return in a function doc comment',
	group: Group::Correctness,
)]
final class NoDuplicateReturnAnnotationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class, FunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof MethodNode && !$node instanceof FunctionNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$found = 0;
		foreach ($context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			if ($child instanceof PhpDocTagNode && strtolower($child->name) === '@return' && ++$found > 1) {
				$context->report($node, 'Only one @return annotation is allowed in a doc comment', trivia: $docComment, fixable: false);
				return;
			}
		}
	}
}
