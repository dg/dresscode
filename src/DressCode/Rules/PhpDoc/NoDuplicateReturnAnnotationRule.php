<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Token;


/**
 * A function documents its return value once; a second `@return` is reported.
 */
#[RuleInfo(
	'dresscode/no-duplicate-return-annotation',
	Stage::Structure,
	description: 'Reports more than one @return in a function doc comment',
)]
final class NoDuplicateReturnAnnotationRule extends Rule
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
				$context->report($node, 'Only one @return annotation is allowed in a doc comment', trivia: $docComment);
				return;
			}
		}
	}
}
