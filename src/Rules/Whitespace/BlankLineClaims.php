<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Line, Value};
use DressCode\Engine\Helpers;
use PhpSyntax\{Node, Trivia};
use function is_int;


/**
 * The claims of blank lines the rules of `blankLines` make: a count read from a decision and the claim of it, one
 * claim per decision and count.
 * @internal
 */
final class BlankLineClaims
{
	/** @var array<string, Claim>  by the decision and the count */
	private array $claims = [];


	/**
	 * The count a value decides, null where the place is left alone.
	 * @return int|array{int, ?int}|null
	 */
	public static function readCount(Value $value): int|array|null
	{
		if ($value->isKept()) {
			return null;
		}

		$range = $value->getCount();
		return $range[0] === $range[1] ? $range[0] : $range;
	}


	/**
	 * The claim of the count made for the decision of the key, and below a comment for another one.
	 * @param  int|array{int, ?int}|null  $count
	 * @param  int|array{int, ?int}|null  $below
	 */
	public function claim(
		int|array|null $count,
		string $key,
		int|array|null $below = null,
		?string $belowKey = null,
		?Line $line = null,
	): ?Claim
	{
		if ($count === null && $below === null && $line === null) {
			return null;
		} elseif ($below === null && $line === null) {
			return $this->claims[$key . ' ' . (is_int($count) ? $count : implode('-', $count ?? []))]
				??= new Claim(blankLines: $count, decision: "blankLines.$key");
		}

		return new Claim(
			line: $line,
			blankLines: $count,
			blankLinesBelowComment: $below,
			decision: "blankLines.$key",
			decisionBelowComment: $belowKey === null ? null : "blankLines.$belowKey",
		);
	}


	/**
	 * The count `afterPhpdoc` gives below the doc comment of a declaration: the last comment above it has to be one,
	 * and not the file's header; the engine counts below the last comment.
	 * @param  int|array{int, ?int}|null  $afterPhpdoc
	 * @return int|array{int, ?int}|null
	 */
	public static function findBelowDocComment(Node $node, int|array|null $afterPhpdoc): int|array|null
	{
		if ($afterPhpdoc === null) {
			return null;
		}

		$leading = $node->getFirstToken()->leadingTrivia ?? [];
		$at = Helpers::findLastCommentIndex($leading);
		return $at !== null && $leading[$at]->is(Trivia::DocComment) && !self::isFileHeader($leading, $at)
			? $afterPhpdoc
			: null;
	}


	/**
	 * A doc comment that is the first thing after the opening tag of the file belongs to the file, not to
	 * the declaration below it.
	 * @param list<Trivia> $leading
	 */
	private static function isFileHeader(array $leading, int $at): bool
	{
		if (!($leading[0] ?? null)?->is(Trivia::OpenTag)) {
			return false;
		}

		return array_all(array_slice($leading, 1, $at - 1), fn(Trivia $trivia) => $trivia->isWhitespace());
	}
}
