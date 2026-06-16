<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine\Gaps;

use DressCode\{Line, RuleContext, Space};
use PhpSyntax\{Indentation, Lexer, Token, Trivia};
use function count;


/**
 * Reports a gap that is not what the claim on it asks for, under the decision of the claim, and
 * fixes it: a line break is put in or taken out, the whitespace of a line and the blank lines of a break are
 * written as claimed. The blank lines and the whitespace of a line that a break has just opened or closed are
 * the business of the next pass, and a violation about them is derived from the one the break was written for.
 * @internal
 */
final readonly class Fixer implements Sink
{
	public function __construct(
		/** @var array<string, RuleContext>  the class of a rule => its context */
		private array $contexts,
	) {
	}


	public function acceptLine(DecidedClaim $claim, ?Token $previous, Token $token, ?Space $space, bool $breaksLine): void
	{
		$wanted = $claim->wanted;
		if (($wanted === Line::Next) === $breaksLine) {
			return;
		}

		$leading = $token->leadingTrivia;
		$context = $this->contexts[$claim->rule::class];
		$at = $claim->side === 'before' || $previous === null ? $token : $previous;
		$message = self::withReason(Messages::formatLine($wanted, $claim->side, $claim->subject, $at), $claim->claim->because);
		$decision = $claim->claim->decisionLine ?? $claim->claim->decision;
		$construct = $claim->construct;
		if ($wanted === Line::Next) {
			if (!$context->reportGap($token, $at, $message, breaks: true, construct: $construct, decision: $decision)) {
				return;
			}

			$style = $context->style;
			$eol = Trivia::fromText($style->lineEnding);
			$tag = $leading[0] ?? null;
			if ($tag?->id === Trivia::OpenTag && !$tag->isLineEnding()) {
				// the code on the line of the open tag goes below it
				$token->replaceTrivia($tag, new Trivia(Trivia::OpenTag, rtrim($tag->text) . $eol->text));
				if (($token->leadingTrivia[1] ?? null)?->id === Trivia::Whitespace) {
					$token->removeTrivia($token->leadingTrivia[1]);
				}

				return;
			}

			if (Resolver::closes($token) && Resolver::findBlankRun($token, closes: true) === null) {
				// the comment on the line of a closing token stays there and the token goes below it
				$end = count($leading);
				while ($end > 0 && $leading[$end - 1]->id === Trivia::Whitespace) {
					$end--;
				}

				$token->setLeadingTrivia([...array_slice($leading, 0, $end), $eol]);

			} elseif ($previous !== null && ($start = self::findTrailingDocComment($previous)) !== null) {
				// a doc comment in front of the token documents it and goes below with it
				$trailing = $previous->trailingTrivia;
				$end = $start;
				while ($end > 0 && $trailing[$end - 1]->id === Trivia::Whitespace) {
					$end--;
				}

				$previous->setTrailingTrivia([...array_slice($trailing, 0, $end), $eol]);
				$token->setLeadingTrivia([...array_slice($trailing, $start), ...$leading]);

			} else {
				$token->ensureStartsLine($eol->text);
			}

			// the line gets the indentation its place in the tree conventionally has; a rule about indentation
			// places it by its own options afterwards
			$token->setIndentation(Indentation::infer($token, $style->toPhpSyntax()));
			return;
		}

		if ($previous === null || ($leading[0] ?? null)?->id === Trivia::OpenTag) {
			return; // the code is never joined to the line of the open tag, so there is nothing to report
		}

		// a comment between the tokens has nowhere to go, so the claim is reported and left unfixed
		if ($previous->hasCommentUpTo($token)) {
			$context->reportGap($token, $at, $message, construct: $construct, decision: $decision);
			return;
		}

		if ($context->reportGap($token, $at, $message, breaks: true, construct: $construct, decision: $decision)) {
			$token->setLeadingTrivia([]);
			$previous->setTrailingTrivia([]);
			$previous->setTrailingSpace($space === Space::None && self::canAdjoin($previous, $token) ? '' : ' ');
		}
	}


	/** Index of the doc comment the trailing trivia of the token ends with, whitespace apart; null when there is none. */
	private static function findTrailingDocComment(Token $token): ?int
	{
		$trailing = $token->trailingTrivia;
		for ($i = count($trailing) - 1; $i >= 0; $i--) {
			if ($trailing[$i]->id === Trivia::DocComment) {
				return $i;
			} elseif ($trailing[$i]->id !== Trivia::Whitespace) {
				return null;
			}
		}

		return null;
	}


	public function acceptSpace(DecidedClaim $claim, Token $previous, Token $token, string $found): void
	{
		$wanted = $claim->wanted;
		if ($wanted === Space::None && $found !== '' && !self::canAdjoin($previous, $token)) {
			$wanted = Space::Single; // no whitespace would make one token of the two: `- -$a` is not `--$a`
		}

		$wrong = match ($wanted) {
			Space::None => $found !== '',
			Space::Single => $found !== ' ',
			Space::SingleOrTabs => $found !== ' ' && !str_contains($found, "\t"),
			Space::AtLeastOne => preg_match('~^ +$~D', $found) !== 1,
			Space::AtLeastOneOrTabs => $found === '',
		};
		if (!$wrong) {
			return;
		}

		$context = $this->contexts[$claim->rule::class];
		$at = $claim->side === 'before' ? $token : $previous;
		$message = self::withReason(Messages::formatSpace($wanted, $claim->side, $at), $claim->claim->because);
		if ($context->reportGap($token, $at, $message, construct: $claim->construct, decision: $claim->claim->decision)) {
			$previous->setTrailingSpace($wanted === Space::None ? '' : ' ');
			if ($token->leadingTrivia !== []) { // the whitespace the resolver found there, the space being the one before
				$token->setLeadingTrivia([]);
			}
		}
	}


	private static function canAdjoin(Token $previous, Token $token): bool
	{
		static $lexer = new Lexer;
		return $lexer->canAdjoin($previous->text, $token->text);
	}


	/** The count is moved to the nearest bound of the range and reported under the claim it violates. */
	public function acceptBlankLines(DecidedClaim $claim, Token $token, array $range, int $from, int $found, ?Trivia $below): void
	{
		[$min, $max] = $range;
		if ($found >= $min && ($max === null || $found <= $max)) {
			return;
		}

		$leading = $token->leadingTrivia;
		if ($below === null) {
			$where = "$claim->side " . Messages::describe($claim->subject);
			$at = $leading[$from] ?? $leading[$from - 1] ?? null; // the first blank line, or the token's own line, or the open tag
		} else {
			$where = $below->id === Trivia::DocComment ? 'after the doc comment' : 'after the comment';
			$at = $leading[$from - 1]; // the line of the comment
		}

		$context = $this->contexts[$claim->rule::class];
		$message = self::withReason(Messages::formatBlankLines($claim->wanted, $where, $found), $claim->claim->because);
		$decision = $below === null ? $claim->claim->decision : $claim->claim->decisionBelowComment ?? $claim->claim->decision;
		if (!$context->reportGap($token, $token, $message, trivia: $at, construct: $claim->construct, decision: $decision)) {
			return;
		}

		$eol = Trivia::fromText($context->style->lineEnding);
		$token->setLeadingTrivia([
			...array_slice($leading, 0, $from),
			...array_fill(0, $found < $min ? $min : (int) $max, $eol),
			...array_slice($leading, $from + $found),
		]);
	}


	private static function withReason(string $message, ?string $because): string
	{
		return $because === null ? "$message." : "$message, because $because.";
	}
}
