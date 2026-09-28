<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;


/**
 * What a reporter learns when a run starts.
 * @internal
 */
final readonly class RunInfo
{
	public function __construct(
		/** the paths of the files are relative to it */
		public string $root,
		public bool $fix,
		public int $fileCount,
		public TypeAnalysisStatus $typeAnalysis = TypeAnalysisStatus::Unavailable,
		/** the configuration lists functions or constants the namespaces declare */
		public bool $namespacesListed = false,
	) {
	}
}
