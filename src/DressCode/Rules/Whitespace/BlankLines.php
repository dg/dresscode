<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\RuleContext;
use Nette\Schema\Elements\AnyOf;
use Nette\Schema\Expect;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function is_int;


/**
 * A count of blank lines as the rules take it in their options: an exact number, a range `[min, max]`
 * with an open end as null, or null to leave the place alone; and the check that fixes a count outside
 * the range to its nearest bound.
 * @internal
 */
final class BlankLines
{
	/** @param int|array{int, ?int}|null $default */
	public static function schema(int|array|null $default): AnyOf
	{
		return Expect::anyOf(
			Expect::int()->min(0),
			// required items: otherwise a null given for the option satisfies the tuple as a pair of defaults
			Expect::tuple([Expect::int()->min(0), Expect::int()->min(0)->nullable()]),
		)->nullable()->default($default);
	}


	/** Blank lines before the token, which must start a line; an open tag before them does not count. */
	public static function countBefore(Token $token): int
	{
		$leading = $token->leadingTrivia;
		$start = ($leading[0] ?? null)?->kind === TriviaKind::OpenTag ? 1 : 0;
		$blank = 0;
		while (($leading[$start + $blank] ?? null)?->kind === TriviaKind::EndOfLine) {
			$blank++;
		}

		return $blank;
	}


	/**
	 * Reports and fixes the blank lines before the token when their count lies outside the range; the count
	 * becomes the nearest bound. Returns whether the count was right.
	 * @param int|array{int, ?int} $count
	 */
	public static function ensure(Token $token, int|array $count, string $what, RuleContext $context): bool
	{
		[$min, $max] = is_int($count) ? [$count, $count] : $count;
		$blank = self::countBefore($token);
		if ($blank >= $min && ($max === null || $blank <= $max)) {
			return true;
		}

		$leading = $token->leadingTrivia;
		$start = ($leading[0] ?? null)?->kind === TriviaKind::OpenTag ? 1 : 0;
		$at = $leading[$start] ?? $leading[0] ?? null; // the blank line, or the token's own line, or the open tag
		if ($context->report($token, self::describe($count, $what, $blank), trivia: $at)) {
			$token->setBlankLinesBefore($blank < $min ? $min : (int) $max, $context->getStyle()->eol);
		}

		return false;
	}


	/** @param int|array{int, ?int} $count */
	public static function describe(int|array $count, string $what, int $found): string
	{
		[$min, $max] = is_int($count) ? [$count, $count] : $count;
		$lines = fn(int $n) => $n . ' blank line' . ($n === 1 ? '' : 's');
		$expected = match (true) {
			$min === $max => $lines($min),
			$max === null => 'at least ' . $lines($min),
			$min === 0 => 'at most ' . $lines($max),
			default => "$min to " . $lines($max),
		};
		return "Expected $expected $what, $found found";
	}
}
