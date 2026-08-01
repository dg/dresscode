<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypelessParamTagValueNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Token;


/**
 * A `@param` naming a parameter the function does not have is reported.
 */
#[RuleInfo(
	'dresscode/no-unknown-param-annotation',
	Stage::Structure,
	description: 'Reports a @param of a parameter the function does not declare',
)]
final class NoUnknownParamAnnotationRule extends Rule
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
		foreach ($node->params->getItems() as $param) {
			if ($param->var->name instanceof Token) {
				$params[$param->var->name->text] = true;
			}
		}

		foreach ($context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			$value = $child instanceof PhpDocTagNode ? $child->value : null;
			if (
				($value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode)
				&& !isset($params[$value->parameterName])
			) {
				$context->report($node, "The @param annotation names {$value->parameterName}, which is not a parameter", trivia: $docComment);
			}
		}
	}
}
