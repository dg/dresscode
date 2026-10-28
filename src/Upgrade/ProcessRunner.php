<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use function is_string;
use const PHP_OS_FAMILY;


/**
 * Runs a command of the project, Composer, git or its tests, through the shell, and ends one that does not end in time
 * as a whole tree, the shell, the command and what it started.
 * @internal
 */
final class ProcessRunner
{
	/**
	 * The exit code and the output of both streams, the exit code null where the command did not end in time.
	 * @param  string|list<string>  $command  a command line, or its program and arguments to quote
	 * @return array{?int, string}
	 */
	public static function run(string|array $command, string $cwd, int $timeout): array
	{
		$command = is_string($command) ? $command : implode(' ', array_map(escapeshellarg(...), $command));
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
		if ($process === false) {
			return [127, 'The command could not be started.'];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], enable: false);
		stream_set_blocking($pipes[2], enable: false);
		$output = '';
		$deadline = microtime(as_float: true) + $timeout;
		while (($status = proc_get_status($process))['running'] && microtime(as_float: true) < $deadline) {
			$output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
			usleep(20_000);
		}

		$output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		if ($status['running']) {
			// proc_terminate() would end the shell alone and leave what it started orphaned
			PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID $status[pid] 2>NUL") : proc_terminate($process);
			proc_close($process);
			return [null, $output];
		}

		proc_close($process);
		return [$status['exitcode'], $output];
	}
}
