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
	 * The decisions to leave unchecked, the costliest first, until the files a fix of the rest would change fit; a
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
			fn(string $decision) => !self::isProtected($decision) && !in_array($decision, $answered, true),
			ARRAY_FILTER_USE_KEY,
		);
		$costs = array_map(count(...), $files);
		arsort($costs);
		$dropped = [];
		foreach (array_keys($costs) as $decision) {
			if (count(array_replace([], ...array_values(array_diff_key($files, array_flip($dropped))))) <= $this->files) {
				break;
			}

			$dropped[] = $decision;
		}

		return $dropped;
	}


	/** Whether the decision is one the others stand on, the indentation of every line above all. */
	public static function isProtected(string $decision): bool
	{
		return in_array($decision, MeasurementPlan::FirstLayer, true) || str_starts_with($decision, 'indentation.');
	}
}
