<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\ConfigurationException;


/**
 * Runs the checks `dresscode init` counts by, each a configuration as `dresscode.neon` writes it over the project,
 * its `paths` naming the files; the root is the project's, wherever the configuration lies.
 * @internal
 */
interface ProbeRunner
{
	/**
	 * @param  array<string, array<string, mixed>>  $probes  key => the configuration
	 * @return array<string, ProbeResult>  by the key of the probe
	 * @throws ConfigurationException
	 */
	function run(array $probes): array;
}
