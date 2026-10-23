<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Config\{Loader, RunnerFactory};
use DressCode\Reporters\NullReporter;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;


/**
 * Runs the probes one after another in the process, through the file a configuration is read from, as the process
 * of `check` reads it; what the tests and a small project need.
 * @internal
 */
final readonly class InProcessProbeRunner implements ProbeRunner
{
	public function __construct(
		private string $root,
	) {
	}


	public function run(array $probes): array
	{
		$file = sys_get_temp_dir() . '/dresscode-probe-' . uniqid() . '.neon';
		$results = [];
		try {
			foreach ($probes as $key => $config) {
				FileSystem::write($file, Neon::encode($config, blockMode: true));
				[$loaded, $root] = Loader::load($file, $this->root, root: $this->root);
				$factory = new RunnerFactory;
				$runner = $factory->createRunner($factory->resolve($loaded, $root), cache: false, baseline: false);
				$results[$key] = ProbeResult::fromRun($runner->run(
					$runner->findFiles($loaded->paths),
					fix: false,
					reporter: new NullReporter,
				));
			}
		} finally {
			@unlink($file); // @ may not have been written
		}

		return $results;
	}
}
