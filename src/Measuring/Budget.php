<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use function count;


/**
 * How many files the first fix after `dresscode init` may change: a value the code holds to in all but a few files is
 * written and those few are fixed, while one whose fix would change more is not checked, so that adopting the
 * configuration rewrites no more of the project than the indentation and the answers of the user do, which do not count.
 * @internal
 */
final readonly class Budget
{
	/** the share of the files a fix may change, and its bounds in files */
	private const Share = 0.01;
	private const MinFiles = 2;
	private const MaxFiles = 20;

	public int $files;


	public function __construct(int $scope, ?int $given = null)
	{
		$this->files = $given ?? max(self::MinFiles, min(self::MaxFiles, (int) ceil(self::Share * $scope)));
	}


	/**
	 * The decisions to leave unchecked until the files the fixes of the rest would change together fit, each time the
	 * one whose files the others change least, so that as few as can be are left, the costlier first among equals; a
	 * protected decision is never left, as the others stand on it, and the files it changes are fixed whatever the
	 * budget, so they do not count against it; neither does a decision the user answered.
	 * @param  array<string, array<string, true>>  $files  decision => the files a fix of it would change
	 * @param  list<string>  $answered
	 * @return list<string>
	 */
	public function findDropped(array $files, array $answered = []): array
	{
		$files = array_filter(
			$files,
			fn(string $decision) => !self::isFoundation($decision) && !in_array($decision, $answered, true),
			ARRAY_FILTER_USE_KEY,
		);
		$union = fn(array $files) => count(array_replace([], ...array_values($files)));
		$dropped = [];
		while ($files !== [] && $union($files) > $this->files) {
			// what leaving each out spares: the files no other decision changes too, the files of its own among equals
			$spared = array_map(fn(string $decision) => [
				$union($files) - $union(array_diff_key($files, [$decision => true])),
				count($files[$decision]),
			], array_combine(array_keys($files), array_keys($files)));
			uasort($spared, fn(array $a, array $b) => $b <=> $a);
			$decision = (string) array_key_first($spared);
			$dropped[] = $decision;
			unset($files[$decision]);
		}

		return $dropped;
	}


	/** Whether the decision is one the others stand on: the indentation, the line ending and the long lines. */
	public static function isFoundation(string $decision): bool
	{
		return in_array($decision, MeasurementPlan::FirstLayer, true) || str_starts_with($decision, 'indentation.');
	}
}
