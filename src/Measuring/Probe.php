<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * A check the measuring counts by: a configuration as `dresscode.neon` writes it over the project, its `paths`
 * naming the files, narrowed as `--only` narrows a run, the values staying as the configuration resolves them.
 * @internal
 */
final readonly class Probe
{
	public function __construct(
		/** @var array<string, mixed> */
		public array $config,
		/** @var list<string>  the decisions the check is narrowed to; none for the whole configuration */
		public array $only = [],
	) {
	}
}
