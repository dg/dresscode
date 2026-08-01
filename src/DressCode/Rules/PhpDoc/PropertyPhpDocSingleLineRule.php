<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * A property doc comment with a single line of content and no description is written on one line,
 * not spread over three.
 */
#[RuleInfo(
	'dresscode/property-phpdoc-single-line',
	Stage::Formatting,
	description: 'Writes a property doc comment with a single line of content on one line',
	modifiesComments: true,
)]
final class PropertyPhpDocSingleLineRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof PropertyNode
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
			|| !str_contains($docComment->text, "\n")
		) {
			return;
		}

		$lines = [];
		foreach (preg_split('~\r\n|\n|\r~', substr($docComment->text, 3, -2)) ?: [] as $line) {
			$line = trim((string) preg_replace('~^[ \t]*\*~', '', $line));
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		$tree = $context->getAnalysis(PhpDoc::class)->parse($docComment);
		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTextNode && trim($child->text) !== '') {
				return;
			}
		}

		$tags = array_filter($tree->children, fn($child) => $child instanceof PhpDocTagNode);
		if (
			count($lines) !== 1
			|| count($tags) !== 1
			|| !$context->report($node, 'A doc comment with a single line of content must be written on one line', trivia: $docComment)
		) {
			return;
		}

		$node->replaceDocComment(new Trivia(TriviaKind::DocComment, "/** $lines[0] */"));
	}
}
