<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\ConfigurationException;


/**
 * Runs the checks `dresscode init` counts by; the root is the project's, wherever the configuration lies.
 * @internal
 */
interface ProbeRunner
{
	/**
	 * @param  array<string, Probe>  $probes
	 * @return array<string, ProbeResult>  by the key of the probe
	 * @throws ConfigurationException
	 */
	function run(array $probes): array;
}
