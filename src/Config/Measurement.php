<?php declare(strict_types=1);

namespace DressCode\Config;

use function sprintf;


/**
 * How a project writes one decision: for every value, how many of the places the decision appears in agree
 * with it. What a place is depends on the decision, a file for one that is a property of the file, a string
 * for one that every string makes anew.
 * @internal
 */
final readonly class Measurement
{
	/** the share of places a value must have for a proposal to write it as the value */
	public const Threshold = 0.7;


	public function __construct(
		/** @var array<int|string, int>  value → places that agree with it */
		public array $agreeing,
		/** places the decision appears in at all */
		public int $opportunities,
		/** what a place is, in the plural: files, strings */
		public string $unit,
	) {
	}


	public function getShare(string $value): float
	{
		return $this->opportunities === 0 ? 0.0 : $this->agreeing[$value] / $this->opportunities;
	}


	/** The value the code agrees with in most places; null when the decision appears nowhere. */
	public function findCommonest(): ?string
	{
		if ($this->opportunities === 0) {
			return null;
		}

		$agreeing = $this->agreeing;
		arsort($agreeing);
		return (string) array_key_first($agreeing);
	}


	/** The value at least the threshold of places agrees with, which a proposal may write as the value. */
	public function findPrevailing(): ?string
	{
		$value = $this->findCommonest();
		return $value !== null && $this->getShare($value) >= self::Threshold ? $value : null;
	}


	/** Every value some place agrees with, and its share, the commonest first: "tab 55%, 4 45% of 290 files". */
	public function describe(): string
	{
		$agreeing = array_filter($this->agreeing);
		arsort($agreeing);
		$parts = [];
		foreach (array_keys($agreeing) as $value) {
			$parts[] = sprintf('%s %d%%', $value, round(100 * $this->getShare((string) $value)));
		}

		return ($parts ? implode(', ', $parts) : 'nothing') . " of $this->opportunities $this->unit";
	}
}
