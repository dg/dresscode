<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function count;


/**
 * The arguments of a call spread over several lines each take a line of their own, indented one unit
 * deeper than the line of the call, with the closing parenthesis back at that line's indentation;
 * a call kept on one line is left alone.
 */
#[RuleInfo(
	'dresscode/multi-line-call',
	Stage::Formatting,
	description: 'Puts every argument of a multi-line call on its own line, indented one level deeper',
	modifiesComments: true,
)]
final class MultiLineCallRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArgumentListNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ArgumentListNode || count($node->args) === 0) {
			return;
		}

		$broken = $node->openParen->getTrailingSpace() === null && !self::hasComment($node->openParen);
		$complete = true;
		foreach ($node->args->getItems() as $arg) {
			$starts = $arg->getFirstToken()?->startsLine() ?? false;
			$broken = $broken || $starts;
			$complete = $complete && $starts;
		}

		$complete = $complete && $node->closeParen->startsLine();
		if (!$broken) {
			return;
		}

		$style = $context->getStyle();
		$base = Indentation::normalize($node->openParen->getLineIndentation(), $style);
		if (!$complete) {
			if ($context->report($node->openParen, 'Every argument of a multi-line call on its own line')) {
				Indentation::breakList($node->args, $node->openParen, $node->closeParen, $base, $style);
			}

			return;
		}

		foreach ($node->args->getItems() as $arg) {
			$first = $arg->getFirstToken();
			if ($first !== null) {
				$this->reindent($first, $base . $style->indent, $arg->getLastToken(), $context);
			}
		}

		$this->reindent($node->closeParen, $base, null, $context);
	}


	private function reindent(Token $token, string $indentation, ?Token $last, RuleContext $context): void
	{
		if (!$token->startsLine() || Indentation::has($token, $indentation, $indentation)) {
			return;
		}

		$leading = $token->leadingTrivia;
		$at = ($leading[count($leading) - 1] ?? null)?->kind === TriviaKind::Whitespace ? $leading[count($leading) - 1] : ($leading[0] ?? null);
		if ($context->report($token, 'Wrong indentation of an argument', trivia: $at)) {
			Indentation::set($token, $indentation, $indentation, $last, $context->getStyle());
		}
	}


	private static function hasComment(Token $token): bool
	{
		foreach ($token->trailingTrivia as $trivia) {
			if ($trivia->isComment()) {
				return true;
			}
		}

		return false;
	}
}
