<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use function count, sprintf;


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

	/** the places from which a value the threshold of them agree with is settled, below which only a value all of them agree with is */
	public const MinPlaces = 10;


	public function __construct(
		/** @var array<int|string, int>  value => places that agree with it */
		public array $agreeing,
		/** places the decision appears in at all */
		public int $opportunities,
		/** what a place is, in the plural: files, strings */
		public string $unit,
		/** places among the opportunities that agree with none of the values */
		public int $neither = 0,
		/** files the rule failed in under some value, left out of the places */
		public int $failed = 0,
	) {
	}


	/**
	 * A decision that is a property of the file, from the probes of its values: a file agrees with a value when its probe
	 * reports nothing in it, and a file no probe reports has none of the decision in it and does not count. Neither does
	 * a file a rule fails in under any of the values, which would otherwise agree with that one.
	 * @param  array<int|string, ProbeResult>  $probes  value => the probe of it; PHP keeps a numeric value as an int key
	 */
	public static function fromFileProbes(array $probes, string $decision): self
	{
		$failed = self::collectFailed($probes);
		$reported = array_map(fn(ProbeResult $probe) => array_diff_key($probe->places[$decision] ?? [], $failed), $probes);
		$opportunities = count(array_replace([], ...array_values($reported)));
		return new self(
			array_map(fn(array $files) => $opportunities - count($files), $reported),
			$opportunities,
			'files',
			failed: count($failed),
		);
	}


	/**
	 * A decision every place makes anew, from the probes of its values and the probe allowing all of them, which counts
	 * the places in none; the places of a file a rule fails in under any of the probes are left out of all of them.
	 * @param  array<int|string, ProbeResult>  $probes  value => the probe of it; PHP keeps a numeric value as an int key
	 */
	public static function fromPlaceProbes(array $probes, string $decision, string $unit, ?ProbeResult $any = null): self
	{
		$failed = self::collectFailed([...array_values($probes), ...($any === null ? [] : [$any])]);
		$count = fn(ProbeResult $probe) => array_sum(array_diff_key($probe->places[$decision] ?? [], $failed));
		return self::fromReportedPlaces(array_map($count, $probes), $unit, $any === null ? 0 : $count($any), count($failed));
	}


	/**
	 * @param  array<int|string, ProbeResult>  $probes
	 * @return array<string, true>
	 */
	private static function collectFailed(array $probes): array
	{
		return array_fill_keys(array_merge(...array_map(fn(ProbeResult $probe) => $probe->failed, array_values($probes))), true);
	}


	/**
	 * The measurement of a decision every place makes anew, from what the rule of each of its k values reports: a place
	 * agreeing with one value is reported by the other k − 1, a place agreeing with none by all k, and the profile
	 * allowing every value reports the latter once, so the places are (reported − neither) / (k − 1).
	 * @param  array<int|string, int>  $reported  value => places its rule reports
	 * @param  int  $neither  places the profile allowing every value reports
	 */
	public static function fromReportedPlaces(array $reported, string $unit, int $neither = 0, int $failed = 0): self
	{
		$opportunities = count($reported) < 2 ? 0 : (int) round((array_sum($reported) - $neither) / (count($reported) - 1));
		return new self(
			array_map(fn(int $count) => max(0, $opportunities - $count), $reported),
			$opportunities,
			$unit,
			$neither,
			$failed,
		);
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


	/** What the code says of the decision, the tolerance counting where the decision takes one. */
	public function classify(bool $tolerance): Classification
	{
		$commonest = $this->findCommonest();
		return match (true) {
			$commonest === null => Classification::Absent,
			$this->opportunities >= self::MinPlaces && $this->getShare($commonest) >= self::Threshold => Classification::Settled,
			$this->opportunities < self::MinPlaces && $this->getShare($commonest) === 1.0 => Classification::SettledWeakly,
			$tolerance && $this->opportunities >= self::MinPlaces && $this->findTolerated() !== null => Classification::Tolerated,
			default => Classification::Mixed,
		};
	}


	/** The value at least the threshold of places agrees with, which a proposal may write as the value. */
	public function findPrevailing(): ?string
	{
		$value = $this->findCommonest();
		return $value !== null && $this->getShare($value) >= self::Threshold ? $value : null;
	}


	/** Every value some place agrees with, and its share, the commonest first: "tab in 55%, 4 spaces in 45% of 290 files". */
	public function describe(): string
	{
		$parts = [];
		foreach ($this->sortAgreeing() as $value) {
			$parts[] = sprintf('%s in %d%%', $value, round(100 * $this->getShare((string) $value)));
		}

		return match (true) {
			$this->opportunities === 0 => "no $this->unit",
			$parts === [] => 'none of the ' . $this->formatPlaces() . ' agrees with any value',
			default => implode(', ', $parts) . ' of ' . $this->formatPlaces() . ($this->neither ? ", $this->neither in none of them" : ''),
		} . ($this->failed ? sprintf(', %d failing %s left out', $this->failed, $this->failed === 1 ? 'file' : 'files') : '');
	}


	/** The places with their unit, "1 file" or "17 conditions". */
	public function formatPlaces(): string
	{
		return $this->opportunities . ' ' . ($this->opportunities === 1 ? substr($this->unit, 0, -1) : $this->unit);
	}


	/**
	 * The values some place agrees with, the commonest first, when together they hold at least the threshold of
	 * the places: as a list, the tolerance of what the code already has.
	 * @return ?list<string>
	 */
	public function findTolerated(): ?array
	{
		if (
			$this->opportunities === 0
			|| ($this->opportunities - $this->neither) / $this->opportunities < self::Threshold
		) {
			return null;
		}

		return array_map(strval(...), $this->sortAgreeing());
	}


	/** @return list<int|string> */
	private function sortAgreeing(): array
	{
		$agreeing = array_filter($this->agreeing);
		arsort($agreeing);
		return array_keys($agreeing);
	}
}
