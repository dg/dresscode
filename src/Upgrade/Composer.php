<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\{ProcessRunner, WorkerPool};
use Nette\Utils\FileSystem;


/**
 * Composer of the project: the packages it installs. Composer is the script `COMPOSER_BINARY` names, run by this PHP as
 * Composer sets it for the scripts it runs, else `composer` on the path.
 * @internal
 */
final readonly class Composer
{
	/** how long Composer may take to update the packages */
	private const UpdateTimeout = 1800;


	public function __construct(
		/** the directory of the composer.json and the lock Composer works with */
		private string $root,
	) {
	}


	/**
	 * Requires the packages at the versions Composer chooses and installs them, the way `composer require` does.
	 * @param  list<string>  $packages
	 * @throws ComposerException
	 */
	public function add(array $packages, bool $dev = false): void
	{
		$this->run(['require', ...$dev ? ['--dev'] : [], ...$packages]);
	}


	/**
	 * @param  list<string>  $arguments
	 * @throws ComposerException  where it does not end in time or fails
	 */
	private function run(array $arguments): void
	{
		$binary = getenv('COMPOSER_BINARY');
		$composer = $binary ? [...WorkerPool::buildPhpCommand() ?? [PHP_BINARY], $binary] : ['composer'];
		$command = [...$composer, ...$arguments, '--no-ansi', '--no-interaction'];
		[$exit, $output] = ProcessRunner::run($command, $this->root, self::UpdateTimeout);
		if ($exit !== 0) {
			$log = $this->keepLog($command, $exit, $output);
			throw new ComposerException(($exit === null
				? 'Composer did not answer within ' . self::UpdateTimeout . ' s'
				: 'Composer failed: ' . (self::findLastLine($output) ?? "exit code $exit"))
				. ($log === null ? '' : "; its whole output is in `$log`"));
		}
	}


	/**
	 * Writes the command, its exit and all it wrote into a file of the temporary directory, as the message keeps only
	 * the last line; null where the file cannot be written.
	 * @param  list<string>  $command
	 */
	private function keepLog(array $command, ?int $exit, string $output): ?string
	{
		$file = sys_get_temp_dir() . '/dresscode-composer-' . date('Ymd-His') . '-' . substr(hash('xxh128', $output), 0, 8) . '.log';
		$head = 'command: ' . implode(' ', $command) . "\ndirectory: $this->root\n"
			. ($exit === null ? 'timeout: ' . self::UpdateTimeout . " s, not answered\n" : "exit code: $exit\n");
		return @file_put_contents($file, "$head\n$output") === false ? null : FileSystem::platformSlashes($file); // @ the temporary directory may refuse it
	}


	private static function findLastLine(string $output): ?string
	{
		$lines = array_filter(array_map(trim(...), preg_split('~\R~', $output) ?: []));
		return $lines === [] ? null : end($lines);
	}
}
