<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function count;


/**
 * Items of a multi-line array indented one unit deeper than the line the array starts on, the closing
 * bracket back at that line's indentation.
 */
#[RuleInfo(
	'dresscode/array-indentation',
	Stage::Formatting,
	description: 'Indents the items of a multi-line array one level deeper than its opening line',
	modifiesComments: true,
)]
final class ArrayIndentationRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArrayNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ArrayNode || $node->openDelimiter->getLine() === $node->closeDelimiter->getLine()) {
			return;
		}

		$style = $context->getStyle();
		$base = Indentation::normalize($node->openDelimiter->getLineIndentation(), $style);
		foreach ($node->items->getItems() as $item) {
			$first = $item->getFirstToken();
			if ($first !== null) {
				$this->reindent($first, $base . $style->indent, $base . $style->indent, $item->getLastToken(), $context);
			}
		}

		// a comment above the closing bracket belongs to the items and keeps their indentation
		$this->reindent($node->closeDelimiter, $base, $base . $style->indent, null, $context);
	}


	private function reindent(
	    Token $token,
	    string $indentation,
	    string $commentIndentation,
	    ?Token $last,
	    RuleContext $context,
	): void
	{
		if (!$token->startsLine() || Indentation::has($token, $indentation, $commentIndentation)) {
			return;
		}

		$leading = $token->leadingTrivia;
		$at = ($leading[count($leading) - 1] ?? null)?->kind === TriviaKind::Whitespace ? $leading[count($leading) - 1] : ($leading[0] ?? null);
		if ($context->report($token, 'Wrong indentation of an array item', trivia: $at)) {
			Indentation::set($token, $indentation, $commentIndentation, $last, $context->getStyle());
		}
	}
}
