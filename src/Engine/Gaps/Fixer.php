<?php declare(strict_types=1);

namespace DressCode\Engine\Gaps;

use DressCode\Line;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Space;
use PhpSyntax\Indentation;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function array_slice, count;


/**
 * Reports a gap that is not what the claim on it asks for, under the name of the rule that made the claim, and
 * fixes it: a line break is put in or taken out, the whitespace of a line and the blank lines of a break are
 * written as claimed. The blank lines and the whitespace of a line that a break has just opened or closed are
 * the business of the next pass.
 * @internal
 */
final class Fixer implements Sink
{
	public function __construct(
		/** @var array<string, RuleContext> by rule name */
		private readonly array $contexts,
	) {
	}


	public function line(array $claim, ?Token $previous, Token $token, ?Space $space, bool $broken): void
	{
		[$rule, $wanted, $subject, $because, $side] = $claim;
		if (($wanted === Line::Next) === $broken) {
			return;
		}

		$leading = $token->leadingTrivia;
		$context = $this->contexts[RuleInfo::of($rule)->name];
		$at = $side === 'before' || $previous === null ? $token : $previous;
		$message = self::withReason(Messages::line($wanted, $side, $subject, $at), $because);
		if ($wanted === Line::Next) {
			if (!$context->report($at, $message)) {
				return;
			}

			$style = $context->getStyle();
			$eol = new Trivia(TriviaKind::EndOfLine, $style->eol);
			$tag = $leading[0] ?? null;
			if ($tag?->kind === TriviaKind::OpenTag && !$tag->isEndOfLine()) {
				// the code on the line of the open tag goes below it
				$token->replaceTrivia($tag, new Trivia(TriviaKind::OpenTag, rtrim($tag->text) . $eol->text));
				if (($token->leadingTrivia[1] ?? null)?->kind === TriviaKind::Whitespace) {
					$token->removeTrivia($token->leadingTrivia[1]);
				}

				return;
			}

			if (Resolver::closes($token) && Resolver::blankRun($token, closes: true) === null) {
				// the comment on the line of a closing token stays there and the token goes below it
				$end = count($leading);
				while ($end > 0 && $leading[$end - 1]->kind === TriviaKind::Whitespace) {
					$end--;
				}

				$token->setLeadingTrivia([...array_slice($leading, 0, $end), $eol]);
			} elseif ($previous !== null && ($start = self::findTrailingDocComment($previous)) !== null) {
				// a doc comment in front of the token documents it and goes below with it
				$trailing = $previous->trailingTrivia;
				$end = $start;
				while ($end > 0 && $trailing[$end - 1]->kind === TriviaKind::Whitespace) {
					$end--;
				}

				$previous->setTrailingTrivia([...array_slice($trailing, 0, $end), $eol]);
				$token->setLeadingTrivia([...array_slice($trailing, $start), ...$leading]);
			} else {
				$token->ensureLeadingNewline($eol->text);
			}

			// the line gets the indentation its place in the tree conventionally has; a rule about indentation
			// places it by its own options afterwards
			$token->setIndentation(Indentation::infer($token, $style));
			return;
		}

		if ($previous === null || ($leading[0] ?? null)?->kind === TriviaKind::OpenTag) {
			return;
		}

		// a comment between the tokens has nowhere to go, so the claim is reported and left unfixed
		if ($previous->hasCommentUpTo($token)) {
			$context->report($at, $message);
			return;
		}

		if ($context->report($at, $message)) {
			$token->setLeadingTrivia([]);
			$previous->setTrailingTrivia([]);
			$previous->setTrailingSpace($space === Space::None ? '' : ' ');
		}
	}


	/** Index of the doc comment the trailing trivia of the token ends with, whitespace apart; null when there is none. */
	private static function findTrailingDocComment(Token $token): ?int
	{
		$trailing = $token->trailingTrivia;
		for ($i = count($trailing) - 1; $i >= 0; $i--) {
			if ($trailing[$i]->kind === TriviaKind::DocComment) {
				return $i;
			} elseif ($trailing[$i]->kind !== TriviaKind::Whitespace) {
				return null;
			}
		}

		return null;
	}


	public function space(array $claim, Token $previous, Token $token, string $found): void
	{
		[$rule, $wanted, , $because, $side] = $claim;
		$wrong = match ($wanted) {
			Space::None => $found !== '',
			Space::Single => $found !== ' ',
			Space::SingleOrTabs => $found !== ' ' && !str_contains($found, "\t"),
			Space::AtLeastSingle => preg_match('~^ +$~D', $found) !== 1,
			Space::AtLeastSingleOrTabs => $found === '',
		};
		if (!$wrong) {
			return;
		}

		$context = $this->contexts[RuleInfo::of($rule)->name];
		$at = $side === 'before' ? $token : $previous;
		if ($context->report($at, self::withReason(Messages::space($wanted, $side, $at), $because))) {
			$previous->setTrailingSpace($wanted === Space::None ? '' : ' ');
		}
	}


	/** The count is moved to the nearest bound of the range and reported under the claim it violates. */
	public function blankLines(array $claim, Token $token, array $range, int $from, int $found, ?Trivia $below): void
	{
		[$min, $max] = $range;
		if ($found >= $min && ($max === null || $found <= $max)) {
			return;
		}

		[$rule, $count, $subject, $because, $side] = $claim;
		$leading = $token->leadingTrivia;
		if ($below === null) {
			$where = "$side " . Messages::describe($subject);
			$at = $leading[$from] ?? $leading[$from - 1] ?? null; // the first blank line, or the token's own line, or the open tag
		} else {
			$where = $below->kind === TriviaKind::DocComment ? 'after the doc comment' : 'after the comment';
			$at = $leading[$from - 1]; // the line of the comment
		}

		$context = $this->contexts[RuleInfo::of($rule)->name];
		$message = self::withReason(Messages::blankLines($count, $where, $found), $because);
		if (!$context->report($token, $message, trivia: $at)) {
			return;
		}

		$eol = new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol);
		$token->setLeadingTrivia([
			...array_slice($leading, 0, $from),
			...array_fill(0, $found < $min ? $min : (int) $max, $eol),
			...array_slice($leading, $from + $found),
		]);
	}


	private static function withReason(string $message, ?string $because): string
	{
		return $because === null ? $message : "$message, $because";
	}
}
