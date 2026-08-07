<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocTagNode, TypelessParamTagValueNode};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * A `@param` naming a parameter the function does not have is reported.
 */
#[RuleInfo(
	'dresscode/no-unknown-param-annotation',
	Stage::Structure,
	description: 'Reports a @param of a parameter the function does not declare',
	group: Group::Correctness,
)]
final class NoUnknownParamAnnotationRule extends NodeRule
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

		$params = [];
		foreach ($node->parameters->getItems() as $param) {
			if ($param->variable->name instanceof Token) {
				$params[$param->variable->name->text] = true;
			}
		}

		foreach ($context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			$value = $child instanceof PhpDocTagNode ? $child->value : null;
			if (
				($value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode)
				&& !isset($params[$value->parameterName])
			) {
				$context->report($node, "The @param annotation names {$value->parameterName}, which is not a parameter", trivia: $docComment, fixable: false);
			}
		}
	}
}
