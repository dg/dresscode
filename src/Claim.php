<?php declare(strict_types=1);

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
	public function __construct(
		public ?Space $space = null,
		public ?Line $line = null,
		/** @var int|array{int, ?int}|null a count, or a range with an open end as null; above the comment when one stands in the gap, because the comment goes with the code below it */
		public int|array|null $blank = null,
		/** @var int|array{int, ?int}|null the blank lines between the last comment in the gap and the token */
		public int|array|null $blankBelowComment = null,
		/** why the claim is made, when it follows from a measure or a property of the code, given after a comma in the message: `A line break before the parameter, the line is 135 characters long` */
		public ?string $because = null,
	) {
	}


	public static function none(): self
	{
		static $claim = new self(Space::None);
		return $claim;
	}


	public static function single(): self
	{
		static $claim = new self(Space::Single);
		return $claim;
	}


	public static function singleOrTabs(): self
	{
		static $claim = new self(Space::SingleOrTabs);
		return $claim;
	}


	public static function atLeastSingle(): self
	{
		static $claim = new self(Space::AtLeastSingle);
		return $claim;
	}


	public static function atLeastSingleOrTabs(): self
	{
		static $claim = new self(Space::AtLeastSingleOrTabs);
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
	public static function blank(int|array $count): self
	{
		static $claims = [];
		return is_int($count) ? $claims[$count] ??= new self(blank: $count) : new self(blank: $count);
	}


	/** Whether the two claim a component both, which two rules may not on one side of one slot. */
	public function overlaps(self $other): bool
	{
		return ($this->space !== null && $other->space !== null)
			|| ($this->line !== null && $other->line !== null)
			|| ($this->blank !== null && $other->blank !== null)
			|| ($this->blankBelowComment !== null && $other->blankBelowComment !== null);
	}
}
