<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use function is_int;


/**
 * What a rule asks of the gap between two tokens: the whitespace when they share a line, whether the second
 * one stands on the line of the first or on the next, how many blank lines when it does, and how many below
 * a comment standing on lines of its own in the gap. A component left null is not claimed. The factories give
 * the claims on one component, shared; a claim on several, or one with a reason, is made through the constructor.
 */
final readonly class Claim
{
	/** the properties a claim decides the gap by, each on its own */
	public const Components = ['space', 'line', 'blankLines', 'blankLinesBelowComment'];


	public function __construct(
		public ?Space $space = null,
		public ?Line $line = null,
		/** @var int|array{int, ?int}|null a count, or a range with an open end as null; above the comment when one stands in the gap, because the comment goes with the code below it, except before a token closing a construct or the file, where it is below it */
		public int|array|null $blankLines = null,
		/** @var int|array{int, ?int}|null the blank lines between the last comment in the gap and the token */
		public int|array|null $blankLinesBelowComment = null,
		/** why the claim is made, when it follows from a measure or a property of the code, given after `because` in the message: `Expected a line break before the parameter, because the line is 135 characters long` */
		public ?string $because = null,
		/** the decision the claim is made for and a violation of it is reported under; the only requirement of the rule when null */
		public ?string $decision = null,
		/** the decision the blank lines below a comment are made for, where it is another one */
		public ?string $decisionBelowComment = null,
		/** the decision the line is made for, where it is another one */
		public ?string $decisionLine = null,
	) {
		self::checkCount('blankLines', $blankLines);
		self::checkCount('blankLinesBelowComment', $blankLinesBelowComment);
	}


	/** @param int|array<mixed>|null $count  as a caller wrote it, the declared shape not yet checked */
	private static function checkCount(string $name, int|array|null $count): void
	{
		$valid = match (true) {
			$count === null => true,
			is_int($count) => $count >= 0,
			default => array_keys($count) === [0, 1]
				&& is_int($count[0])
				&& $count[0] >= 0
				&& ($count[1] === null || (is_int($count[1]) && $count[1] >= $count[0])),
		};
		if (!$valid) {
			throw new \InvalidArgumentException("The `$name` of a claim must be a count of at least zero or a range `[min, max]` with `min` at most `max` and null for an open end, `" . json_encode($count) . '` given.');
		}
	}


	public static function noSpace(): self
	{
		static $claim = new self(Space::None);
		return $claim;
	}


	public static function singleSpace(): self
	{
		static $claim = new self(Space::Single);
		return $claim;
	}


	public static function singleSpaceOrTabs(): self
	{
		static $claim = new self(Space::SingleOrTabs);
		return $claim;
	}


	public static function atLeastOneSpace(): self
	{
		static $claim = new self(Space::AtLeastOne);
		return $claim;
	}


	public static function atLeastOneSpaceOrTabs(): self
	{
		static $claim = new self(Space::AtLeastOneOrTabs);
		return $claim;
	}


	public static function sameLine(): self
	{
		static $claim = new self(line: Line::Same);
		return $claim;
	}


	public static function nextLine(): self
	{
		static $claim = new self(line: Line::Next);
		return $claim;
	}


	/** @param int|array{int, ?int} $count */
	public static function blankLines(int|array $count): self
	{
		static $claims = [];
		return is_int($count) ? $claims[$count] ??= new self(blankLines: $count) : new self(blankLines: $count);
	}


	/**
	 * A count of blank lines as the range it allows, an exact count being the range of itself.
	 * @param int|array{int, ?int} $count
	 * @return array{int, ?int}
	 */
	public static function toRange(int|array $count): array
	{
		return is_int($count) ? [$count, $count] : $count;
	}


	/** The same claim made for the decision, and for another one below a comment and for the line where given. */
	public function withDecision(string $decision, ?string $belowComment = null, ?string $lineDecision = null): self
	{
		return new self($this->space, $this->line, $this->blankLines, $this->blankLinesBelowComment, $this->because, $decision, $belowComment, $lineDecision);
	}


	/** Whether the two claim a component both, which two rules may not on one side of one slot. */
	public function overlaps(self $other): bool
	{
		return ($this->space !== null && $other->space !== null)
			|| ($this->line !== null && $other->line !== null)
			|| ($this->blankLines !== null && $other->blankLines !== null)
			|| ($this->blankLinesBelowComment !== null && $other->blankLinesBelowComment !== null);
	}
}
