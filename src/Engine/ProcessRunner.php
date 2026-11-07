<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use function count;
use const PHP_OS_FAMILY;


/**
 * Runs a command and ends one that does not end in time as a whole tree, the shell, the command and what it started,
 * keeping the end of its output where it writes more than is ever read.
 * @internal
 */
final class ProcessRunner
{
	/** how much of the output is kept, its end */
	private const MaxOutput = 8 * 1024 * 1024;


	/**
	 * The exit code and the output of both streams, the exit code null where the command did not end in time.
	 * @param  list<string>  $command  the program and its arguments
	 * @return array{?int, string}
	 */
	public static function run(array $command, string $cwd, int $timeout): array
	{
		// the shell of Windows is what finds a program that is a `.bat`
		$command = PHP_OS_FAMILY !== 'Windows'
			? $command
			: implode(' ', array_map(escapeshellarg(...), $command));
		// files rather than pipes, which Windows reads only blocking, so that a command holding them open still ends in time
		[$stdin, $stdout] = [tmpfile(), tmpfile()];
		if ($stdin === false || $stdout === false) {
			throw new \RuntimeException('A temporary file could not be created.');
		}

		$process = proc_open($command, [0 => $stdin, 1 => $stdout, 2 => $stdout], $pipes, $cwd);
		if ($process === false) {
			return [127, 'The command could not be started.'];
		}

		$deadline = microtime(as_float: true) + $timeout;
		while (($status = proc_get_status($process))['running'] && microtime(as_float: true) < $deadline) {
			usleep(20_000);
		}

		if ($status['running']) {
			self::killTree($status['pid']);
		}

		proc_close($process);
		fseek($stdout, max(0, (int) fstat($stdout)['size'] - self::MaxOutput));
		return [$status['running'] ? null : $status['exitcode'], (string) stream_get_contents($stdout)];
	}


	/**
	 * Ends the process and every process it started at once, as ending the shell alone would leave what it started
	 * orphaned.
	 */
	private static function killTree(int $pid): void
	{
		if (PHP_OS_FAMILY === 'Windows') {
			exec("taskkill /F /T /PID $pid 2>NUL");
			return;
		}

		$tree = [$pid];
		for ($i = 0; $i < count($tree); $i++) {
			exec("pgrep -P {$tree[$i]} 2>/dev/null", $children);
			array_push($tree, ...array_map('intval', $children));
			$children = [];
		}

		exec('kill -9 ' . implode(' ', $tree) . ' 2>/dev/null');
	}
}
