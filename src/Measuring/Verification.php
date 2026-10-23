<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use function count;


/**
 * What a check of the configuration `dresscode init` writes says over the whole scope: what the first fix changes and
 * what it leaves for the baseline.
 * @internal
 */
final readonly class Verification
{
	/** the files a fix changes */
	public int $changedFiles;

	/** the files among them a decision changes that the others stand on, the indentation of every line above all */
	public int $alignedFiles;

	/** the places a fix leaves, having no fix or a risky one */
	public int $leftPlaces;

	/** the files those places are in */
	public int $leftFiles;


	public function __construct(
		public ProbeResult $result,
		/** the files the first fix may change */
		public int $budget,
	) {
		$this->changedFiles = $result->changedFiles;
		$protected = array_filter($result->fixable, Budget::isProtected(...), ARRAY_FILTER_USE_KEY);
		$this->alignedFiles = count(array_replace([], ...array_values($protected)));
		$files = $places = [];
		foreach ($result->left as $byFile) {
			$places[] = array_sum($byFile);
			$files += $byFile;
		}

		$this->leftPlaces = array_sum($places);
		$this->leftFiles = count($files);
	}
}
