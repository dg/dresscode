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

	/** the files among them a decision the others stand on changes, the indentation, the line ending or the long lines */
	public int $foundationFiles;

	/** the findings a fix leaves, having no fix or a risky one */
	public int $leftFindings;

	/** the files those findings are in */
	public int $leftFiles;


	public function __construct(
		public ProbeResult $result,
		/** the files the first fix may change */
		public int $budget,
	) {
		$this->changedFiles = $result->changedFiles;
		$foundation = array_filter($result->fixable, Budget::isFoundation(...), ARRAY_FILTER_USE_KEY);
		$this->foundationFiles = count(array_replace([], ...array_values($foundation)));
		$files = $findings = [];
		foreach ($result->left as $byFile) {
			$findings[] = array_sum($byFile);
			$files += $byFile;
		}

		$this->leftFindings = array_sum($findings);
		$this->leftFiles = count($files);
	}
}
