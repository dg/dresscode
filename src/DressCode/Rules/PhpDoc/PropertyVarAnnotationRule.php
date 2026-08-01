<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function count;


/**
 * A property doc comment holds a single `@var` and holds it first. Reported.
 */
#[RuleInfo(
	'dresscode/property-var-annotation',
	Stage::Structure,
	description: 'Reports a repeated or misplaced @var in a property doc comment',
)]
final class PropertyVarAnnotationRule extends Rule
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

		$tags = [];
		foreach ($context->getAnalysis(PhpDoc::class)->parse($comment)->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				$tags[] = $child;
			}
		}

		$vars = array_filter($tags, fn(PhpDocTagNode $tag) => strtolower($tag->name) === '@var');
		if (count($vars) > 1) {
			$context->report($node, 'Only one @var annotation is allowed in a property doc comment', trivia: $comment);
		} elseif ($vars && strtolower($tags[0]->name) !== '@var') {
			$context->report($node, 'The @var annotation must be the first one in a property doc comment', trivia: $comment);
		}
	}
}
