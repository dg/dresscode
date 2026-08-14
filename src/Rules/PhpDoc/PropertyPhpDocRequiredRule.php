<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * What documents a property is a doc comment, not a plain one, whatever its syntax. A comment separated
 * from the property by a blank line does not document it. Reported.
 */
#[RuleInfo(
	'dresscode/property-phpdoc-required',
	Stage::Structure,
	description: 'Reports a plain comment in place of a property doc comment',
)]
final class PropertyPhpDocRequiredRule extends NodeRule
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
		$lineBreaks = 0;
		foreach ($first->leadingTrivia as $trivia) {
			if ($trivia->isComment()) {
				$comment = $trivia;
				$lineBreaks = 0;
			} elseif ($trivia->isEndOfLine()) {
				$lineBreaks++;
			}
		}

		if (
			$comment !== null
			&& $lineBreaks < 2
			&& !$comment->inInterpolation
			&& $comment->kind !== TriviaKind::DocComment
		) {
			$context->report($node, 'A property must be documented with a doc comment, not a plain comment', trivia: $comment);
		}
	}
}
