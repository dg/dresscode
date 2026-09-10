<?php declare(strict_types=1);

namespace DressCode\Rules;

use Nette\Schema\Elements\AnyOf;
use Nette\Schema\Expect;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function is_int;


/**
 * A count of blank lines as the rules take it in their options: an exact number, a range `[min, max]`
 * with an open end as null, or `keep` to leave the place alone.
 * @internal
 */
final class BlankLines
{
	public const Keep = 'keep';


	/** @param int|array{int, ?int}|self::Keep $default */
	public static function schema(int|array|string $default): AnyOf
	{
		return Expect::anyOf(
			Expect::int()->min(0),
			// required items: otherwise a null given for the option satisfies the tuple as a pair of defaults
			Expect::tuple([Expect::int()->min(0), Expect::int()->min(0)->nullable()]),
			self::Keep,
		)->default($default);
	}


	/**
	 * The count a rule works with, where the place it leaves alone is null.
	 * @param  int|array{int, ?int}|self::Keep  $value
	 * @return int|array{int, ?int}|null
	 */
	public static function count(int|array|string $value): int|array|null
	{
		return $value === self::Keep ? null : $value;
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
	 * The range all the counts allow; when two exclude each other, the one given last wins.
	 * @param list<int|array{int, ?int}> $counts
	 * @return int|array{int, ?int}|null
	 */
	public static function intersect(array $counts): int|array|null
	{
		$result = null;
		foreach ($counts as $count) {
			[$min, $max] = is_int($count) ? [$count, $count] : $count;
			if ($result !== null) {
				[$currentMin, $currentMax] = $result;
				$min = max($min, $currentMin);
				$max = $max === null ? $currentMax : ($currentMax === null ? $max : min($max, $currentMax));
				if ($max !== null && $min > $max) {
					[$min, $max] = is_int($count) ? [$count, $count] : $count;
				}
			}

			$result = [$min, $max];
		}

		return $result === null ? null : ($result[0] === $result[1] ? $result[0] : $result);
	}
}
