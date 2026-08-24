<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Token;
use function count;


/**
 * No `@var` on a class constant, whose type is always clear from its value; a tag with a description stays,
 * and so does a type that is more than a bare name (`int[]`, `array<string, Foo>`, `?int`).
 */
#[RuleInfo(
	'dresscode/useless-constant-var-annotation',
	Stage::Cleanup,
	description: 'Removes a useless @var from a class constant',
	modifiesComments: true,
)]
final class UselessConstantVarAnnotationRule extends NodeRule
{
	public function getVisitedTypes(): array
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
			|| !$context->report($node, 'Useless @var annotation on a constant', trivia: $docComment)
		) {
			return;
		}

		$tree->children = array_values($kept);
		if (PhpDoc::isEmpty($tree)) {
			$node->removeDocComment();
		} else {
			$node->replaceDocComment($phpDoc->print($tree, $docComment));
		}
	}
}
