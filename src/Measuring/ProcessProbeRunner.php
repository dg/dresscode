<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\ConfigurationException;
use DressCode\Engine\WorkerPool;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count, dirname, is_array;
use const PHP_OS_FAMILY;


/**
 * Runs the probes as processes of `check --format json`, as many at once as the machine has processors, the processors
 * shared among those running: a probe over a sample is cheaper alone in a process than with a pool of workers to start,
 * and a few probes over many files get the workers between them. The configurations lie in the system temp, never in
 * the project, and `--root` makes the project the root of the run. The script is always the one of this DressCode, and a
 * probe starts no probes of its own, so the processes never grow beyond one level, however the runner is called.
 * @internal
 */
final readonly class ProcessProbeRunner implements ProbeRunner
{
	/** how long the loop waits between two looks at the processes, in microseconds */
	private const PollInterval = 20_000;

	/** the variable of the environment a probe runs with */
	private const ProbeVariable = 'DRESSCODE_PROBE';


	public function __construct(
		private string $root,
		/** @var list<string>  the PHP and the script of DressCode */
		private array $command,
		private int $processors,
	) {
	}


	public static function create(string $root): self
	{
		$script = \Phar::running(returnPhar: false) ?: dirname(__DIR__, 2) . '/bin/dresscode';
		return new self($root, [...WorkerPool::buildPhpCommand() ?? [PHP_BINARY], $script], WorkerPool::detectCpuCount());
	}


	public function run(array $probes): array
	{
		if (getenv(self::ProbeVariable) !== false) {
			throw new \LogicException('A probe of `dresscode init` starts no probes of its own.');
		}

		$dir = sys_get_temp_dir() . '/dresscode-probes-' . uniqid();
		FileSystem::createDir($dir);
		$parallel = max(1, min($this->processors, count($probes)));
		$jobs = max(1, intdiv($this->processors, $parallel));
		$queue = array_keys($probes);
		$running = $results = [];
		try {
			while ($queue || $running) {
				while ($queue && count($running) < $parallel) {
					$key = array_shift($queue);
					$running[$key] = $this->start($probes[$key], $dir . '/' . md5((string) $key), $jobs);
				}

				foreach ($running as $key => [$process, $file]) {
					// the exit code is told once, by the first look after the end, and proc_close() then has none
					$status = proc_get_status($process);
					if ($status['running']) {
						continue;
					}

					proc_close($process);
					unset($running[$key]);
					$results[$key] = self::readResult($file, $status['exitcode']);
				}

				if ($running) {
					usleep(self::PollInterval);
				}
			}
		} finally {
			foreach ($running as [$process]) {
				proc_terminate($process);
			}

			FileSystem::delete($dir);
		}

		return $results;
	}


	/**
	 * @param  array<string, mixed>  $config
	 * @return array{resource, string}  the process and the base name of its files
	 */
	private function start(array $config, string $file, int $jobs): array
	{
		FileSystem::write("$file.neon", Neon::encode($config, blockMode: true));
		$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
		$process = proc_open(
			[...$this->command, 'check', '--format=json', "--jobs=$jobs", '--no-cache', "--root=$this->root", "--config=$file.neon"],
			[0 => ['file', $null, 'r'], 1 => ['file', "$file.json", 'w'], 2 => ['file', "$file.err", 'w']],
			$pipes,
			$this->root,
			[...getenv(), self::ProbeVariable => '1'],
		);
		if ($process === false) {
			throw new \RuntimeException('A process of `check` could not be started.');
		}

		return [$process, $file];
	}


	/** @throws ConfigurationException */
	private static function readResult(string $file, int $exit): ProbeResult
	{
		$report = json_decode((string) @file_get_contents("$file.json"), associative: true); // @ written by the process
		if ($exit > 2 || !is_array($report)) { // 2 is a file a rule failed in, which the report carries
			$error = trim((string) @file_get_contents("$file.err")); // @ written by the process
			throw new ConfigurationException("A check of the measuring ended with exit code $exit: " . ($error ?: 'no output'));
		}

		return ProbeResult::fromJson($report);
	}
}
