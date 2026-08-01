<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * A `@var` or `@see` in a property doc comment says something; the other tags may stand alone, as
 * `@internal` and `@deprecated` do. Reported.
 */
#[RuleInfo(
	'dresscode/no-empty-var-annotation',
	Stage::Structure,
	description: 'Reports a @var or @see without content in a property doc comment',
)]
final class NoEmptyVarAnnotationRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode || ($first = $node->getFirstToken()) === null) {
			return;
		}

		$comment = null;
		foreach ($first->leadingTrivia as $trivia) {
			if ($trivia->isComment()) {
				$comment = $trivia;
			}
		}

		if ($comment === null || $comment->inInterpolation || $comment->kind !== TriviaKind::DocComment) {
			return;
		}

		foreach ($context->getAnalysis(PhpDoc::class)->parse($comment)->children as $child) {
			$name = $child instanceof PhpDocTagNode ? strtolower($child->name) : null;
			$empty = $child instanceof PhpDocTagNode
				&& (
					$child->value instanceof InvalidTagValueNode
					|| ($child->value instanceof GenericTagValueNode && trim($child->value->value) === '')
				);
			if ($empty && ($name === '@var' || $name === '@see')) {
				$context->report($node, "The $name annotation has no content", trivia: $comment);
			}
		}
	}
}
