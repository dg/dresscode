<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Config\{Loader, RunnerFactory};
use DressCode\ConfigurationException;
use DressCode\Engine\Runner;
use DressCode\Reporters\NullReporter;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count;


/**
 * Runs the probes one after another in the process, through the file a configuration is read from, as the process
 * of `check` reads it; what the tests need, a run where no PHP binary can be started, and the fix of the one file a
 * question of init shows.
 * @internal
 */
final readonly class InProcessProbeRunner implements ProbeRunner
{
	public function __construct(
		private string $root,
		/** @var ?\Closure(int, int): void  the probes done and all of them, told as each one ends */
		private ?\Closure $onProgress = null,
	) {
	}


	public function run(array $probes): array
	{
		$file = self::createConfigPath();
		$results = [];
		try {
			foreach ($probes as $key => $probe) {
				[$runner, $paths] = $this->createRunner($probe, $file);
				$run = $runner->run($runner->files->findFiles($paths), fix: false, reporter: new NullReporter);
				$results[$key] = ProbeResult::fromRun($run);
				($this->onProgress)?->__invoke(count($results), count($probes));
			}
		} finally {
			@unlink($file); // @ may not have been written
		}

		return $results;
	}


	/**
	 * The code of the file as a fix under the probe writes it, nothing written; null where it does not parse or a rule
	 * fails in it.
	 * @throws ConfigurationException
	 */
	public function fix(Probe $probe, string $path): ?string
	{
		$file = self::createConfigPath();
		try {
			$result = $this->createRunner($probe, $file)[0]->processPath($path, fix: false);
		} finally {
			@unlink($file); // @ may not have been written
		}

		return $result->syntaxError === null && $result->failure === null ? $result->output ?? $result->code : null;
	}


	/**
	 * The runner of the probe, through the file its configuration is written to, and the paths it names.
	 * @return array{Runner, list<string>}
	 */
	private function createRunner(Probe $probe, string $file): array
	{
		FileSystem::write($file, Neon::encode($probe->config, blockMode: true));
		[$loaded, $root] = Loader::load($file, $this->root, root: $this->root);
		$factory = new RunnerFactory;
		$resolved = $factory->resolve($loaded, $root, only: $probe->only ?: null);
		return [$factory->createRunner($resolved, cache: false, baseline: false), $loaded->paths];
	}


	private static function createConfigPath(): string
	{
		return sys_get_temp_dir() . '/dresscode-probe-' . uniqid(more_entropy: true) . '.neon';
	}
}
