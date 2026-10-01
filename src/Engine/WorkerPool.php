<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\FileResult;
use function array_key_exists, count, function_exists, is_array, strlen;
use const PHP_OS_FAMILY;


/**
 * Processes files in worker processes: a TCP server on the loopback hands the paths out one at a time,
 * a worker sends each result back as a line of JSON. The parent keeps the cache and the baseline, a worker
 * only processes and, when fixing, writes.
 * @internal
 */
final class WorkerPool
{
	/**
	 * The listening socket: from ext/sockets when available, because the stream layer on Windows waits 500 ms
	 * for a socket to become writable before closing it, which a listening one never does.
	 * @var \Socket|resource|null
	 */
	private mixed $server = null;

	/** @var array<int, resource>  connected workers by socket id */
	private array $sockets = [];

	/** @var array<int, string>  what a worker sent after its last complete line, by socket id */
	private array $buffers = [];

	/** workers that have connected so far */
	private int $accepted = 0;

	/** workers started once the first has connected, and the address they connect to */
	private int $pending = 0;

	private string $address = '';

	/** @var array<int, array{string, float, string}>  path in progress with the time it started and its content, by socket id */
	private array $inProgress = [];

	/** @var ?\Closure(string): string  path => content, read when the path is handed out */
	private ?\Closure $read = null;

	/** @var list<array{resource, string}>  process, file with its output */
	private array $workers = [];

	/** @var list<string> */
	private array $queue = [];

	/** @var array<int, true>  workers asked for what they measured last, by socket id */
	private array $finishing = [];


	public function __construct(
		/** @var list<string> the worker command line; --worker with the address is appended */
		private readonly array $command,
		private readonly int $jobs,
		/** working directory of the workers */
		private readonly ?string $cwd = null,
		/** seconds a worker may spend on one file before the run fails, since a rule has most likely looped */
		private readonly int $taskTimeout = 300,
		/** with it, every worker hands over what it measured last once nothing is left, one that got no file among them */
		private readonly ?Profiler $profiler = null,
		/** the first worker fills a cache the others share before it connects, and they start after that */
		private readonly bool $warmFirst = false,
	) {
	}


	/** Processors the machine has, 1 when unknown. */
	public static function detectCpuCount(): int
	{
		$count = (int) getenv('NUMBER_OF_PROCESSORS');
		if ($count < 1 && is_readable('/proc/cpuinfo')) {
			$count = preg_match_all('~^processor\s*:~m', (string) file_get_contents('/proc/cpuinfo'));
		}

		return max(1, $count);
	}


	/**
	 * The PHP a worker is started with: the same binary with the same ini file, the binary alone loading the default
	 * one, and the settings the process has beyond it.
	 * @return list<string>
	 */
	public static function buildPhpCommand(): array
	{
		$ini = php_ini_loaded_file();
		$php = [PHP_BINARY, ...($ini === false ? (php_ini_scanned_files() === false ? ['-n'] : []) : ['-c', $ini])];
		return [...$php, ...self::findIniOverrides($php)];
	}


	/**
	 * The settings the process has and the PHP of the command alone would not, those given by -d above all, as the
	 * options -d that give them to a worker; PHP tells no process the options it started with, so the PHP of the
	 * command is asked for its settings and they are compared.
	 * @param  list<string>  $php
	 * @return list<string>
	 */
	private static function findIniOverrides(array $php): array
	{
		$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
		$process = @proc_open([...$php, '-r', 'echo serialize(ini_get_all(null, false));'], [1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes); // @ - no settings then
		if ($process === false) {
			return [];
		}

		$base = @unserialize((string) stream_get_contents($pipes[1])); // @ - a PHP that failed to start gives none
		fclose($pipes[1]);
		proc_close($process);
		$own = ini_get_all(null, false);
		if (!is_array($base) || $own === false) {
			return [];
		}

		$options = [];
		foreach ($own as $name => $value) {
			if (array_key_exists($name, $base) && $base[$name] !== $value) {
				$options[] = '-d';
				$options[] = "$name=$value";
			}
		}

		return $options;
	}


	/**
	 * The results by path, each as soon as it arrives; the content of a file is read when it is handed out, so that
	 * only the files in progress are held.
	 * @param  list<string>  $paths
	 * @param  \Closure(string): string  $read  path => content
	 * @param  ?\Closure(int, array<string, float>): void  $onProgress  files done and the paths in progress
	 * @return \Generator<string, FileResult>
	 * @throws \RuntimeException  when a worker fails
	 */
	public function process(array $paths, \Closure $read, ?\Closure $onProgress = null): \Generator
	{
		$address = $this->listen();
		$this->queue = $paths;
		$this->read = $read;
		$done = 0;
		try {
			$count = min($this->jobs, count($this->queue));
			$this->address = $address;
			$this->pending = $this->warmFirst ? $count - 1 : 0;
			for ($i = $count - $this->pending; $i > 0; $i--) {
				$this->workers[] = $this->spawn($address);
			}

			while ($done < count($paths) || $this->isFinishing()) {
				foreach ($this->await() as $stream) {
					foreach ($this->receive((int) $stream) as $path => $result) {
						$done++;
						yield $path => $result;
					}
				}

				$this->checkWorkers($done < count($paths));
				if ($onProgress !== null) {
					$onProgress($done, $this->getInProgress());
				}
			}

		} finally {
			foreach ($this->sockets as $socket) {
				fclose($socket);
			}

			$this->closeServer();
			$this->stop();
		}
	}


	/** @return string  the address the workers connect to */
	private function listen(): string
	{
		if (function_exists('socket_create')) {
			$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			if (
				$server === false
				|| !socket_bind($server, '127.0.0.1')
				|| !socket_listen($server, $this->jobs)
				|| !socket_getsockname($server, $ip, $port)
			) {
				throw new \RuntimeException('Cannot start the worker server: ' . socket_strerror(socket_last_error()));
			}

			$this->server = $server;
			return "$ip:$port";
		}

		$context = stream_context_create(['socket' => ['tcp_nodelay' => true]]); // Nagle would delay every small message by an ACK
		$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error, context: $context); // @ - reported as exception
		if ($server === false) {
			throw new \RuntimeException("Cannot start the worker server: $error");
		}

		$this->server = $server;
		return (string) stream_socket_get_name($server, remote: false);
	}


	/**
	 * Waits for messages from the workers, accepting the ones that connect meanwhile; the listening socket
	 * from ext/sockets is closed as soon as the last worker is in.
	 * @return list<resource>  connections with a message waiting
	 */
	private function await(): array
	{
		$read = array_values($this->sockets);
		$write = $except = [];
		$seconds = 1;
		$microseconds = 0;
		$server = $this->server;
		if ($server instanceof \Socket) {
			$this->acceptPending($server, blocking: $read === []);
			if ($this->accepted === count($this->workers)) {
				$this->closeServer();
			} else { // short waits while workers may still be connecting
				$seconds = 0;
				$microseconds = 10_000;
			}
		} elseif ($server !== null) {
			$read[] = $server;
		}

		if ($read === []) { // nothing connected: the workers are still starting, or they are gone
			return [];
		}

		if (@stream_select($read, $write, $except, $seconds, $microseconds) === false) { // @ - reported as exception
			throw new \RuntimeException('Waiting for the workers failed.');
		}

		$streams = [];
		foreach ($read as $stream) {
			if ($stream === $server) {
				$this->acceptStream($stream);
			} else {
				$streams[] = $stream;
			}
		}

		return $streams;
	}


	/** Accepts every worker waiting to connect; with nothing to do otherwise, waits for the first one. */
	private function acceptPending(\Socket $server, bool $blocking): void
	{
		while (true) {
			$read = [$server];
			$write = $except = null;
			if (socket_select($read, $write, $except, $blocking ? 1 : 0) !== 1) {
				return;
			}

			$blocking = false;
			$connection = socket_accept($server);
			if ($connection === false) {
				return;
			}

			socket_set_option($connection, SOL_TCP, TCP_NODELAY, 1); // Nagle would delay every small message by an ACK
			$stream = socket_export_stream($connection);
			if ($stream === false) {
				throw new \RuntimeException('Cannot use the connection of a worker.');
			}

			$this->connected($stream);
		}
	}


	/** @param resource $server */
	private function acceptStream($server): void
	{
		$socket = @stream_socket_accept($server, 0); // @ - a false alarm is harmless
		if ($socket !== false) {
			$this->connected($socket);
		}
	}


	/**
	 * Registers the connection, starts the workers that waited for it, and hands it a path. It is read without blocking: a worker may have sent only a part
	 * of its line, and waiting for the rest would stop the parent from hearing the others and from noticing a worker
	 * that is stuck.
	 * @param resource $socket
	 */
	private function connected($socket): void
	{
		stream_set_blocking($socket, false);
		$this->accepted++;
		for (; $this->pending > 0; $this->pending--) {
			$this->workers[] = $this->spawn($this->address);
		}

		$id = (int) $socket;
		$this->sockets[$id] = $socket;
		$this->assign($id);
	}


	private function closeServer(): void
	{
		if ($this->server instanceof \Socket) {
			socket_close($this->server);
		} elseif ($this->server !== null) {
			fclose($this->server);
		}

		$this->server = null;
	}


	/**
	 * The results the worker has finished sending, by path: every line answers the path in progress, and a line
	 * about any other one is a failure, as is an ended worker with a path in progress.
	 * @return array<string, FileResult>
	 */
	private function receive(int $id): array
	{
		$socket = $this->sockets[$id] ?? null;
		if ($socket === null) {
			return [];
		}

		$chunk = fread($socket, 1 << 20);
		if ($chunk === false || ($chunk === '' && feof($socket))) {
			if (isset($this->inProgress[$id])) {
				throw new \RuntimeException("A worker ended while processing `{$this->inProgress[$id][0]}`." . $this->collectErrors());
			}

			$this->disconnect($id);
			return [];
		}

		$buffer = ($this->buffers[$id] ?? '') . $chunk;
		$results = [];
		while (($end = strpos($buffer, "\n")) !== false) {
			$data = json_decode(substr($buffer, 0, $end), associative: true);
			$buffer = substr($buffer, $end + 1);
			if (isset($this->finishing[$id]) && is_array($data['profile'] ?? null)) {
				$this->profiler?->merge($data['profile']);
				$this->disconnect($id);
				break;
			}

			[$path, , $code] = $this->inProgress[$id] ?? [null, null, ''];
			if (!is_array($data) || $path === null || ($data['path'] ?? null) !== $path) {
				throw new \RuntimeException('A worker sent an unexpected message.' . $this->collectErrors());
			}

			unset($this->inProgress[$id]);
			[$results[$path], $profile] = WorkerCodec::decode($data, $code);
			if ($profile !== null) {
				$this->profiler?->merge($profile);
			}

			$this->assign($id);
		}

		if (isset($this->sockets[$id]) && strlen($buffer)) {
			$this->buffers[$id] = $buffer;
		} else {
			unset($this->buffers[$id]);
		}

		return $results;
	}


	/** Hands the next path to the worker; when there is none, asks it for what it measured last, or closes its connection. */
	private function assign(int $id): void
	{
		$path = array_shift($this->queue);
		if ($path === null && $this->profiler !== null) {
			$this->finishing[$id] = true;
			fwrite($this->sockets[$id], json_encode(['finish' => true], JSON_THROW_ON_ERROR) . "\n");
			return;
		} elseif ($path === null) {
			$this->disconnect($id);
			return;
		}

		$read = $this->read ?? throw new \LogicException('No run is in progress.');
		$this->inProgress[$id] = [$path, microtime(as_float: true), $read($path)];
		fwrite($this->sockets[$id], json_encode(['path' => $path], JSON_THROW_ON_ERROR) . "\n");
	}


	/** @return array<string, float>  path in progress => the time it started */
	private function getInProgress(): array
	{
		$paths = [];
		foreach ($this->inProgress as [$path, $since]) {
			$paths[$path] = $since;
		}

		return $paths;
	}


	private function disconnect(int $id): void
	{
		fclose($this->sockets[$id]);
		unset($this->sockets[$id], $this->inProgress[$id], $this->buffers[$id], $this->finishing[$id]);
	}


	/**
	 * Whether the run still waits for what the workers measured last: from one asked for it, and from one still
	 * starting, which gets nothing to process but has its peak memory.
	 */
	private function isFinishing(): bool
	{
		if ($this->profiler === null) {
			return false;
		} elseif ($this->finishing !== []) {
			return true;
		}

		// which process a connection belongs to is unknown, so a worker done with its work is waited for too, until it exits
		return $this->accepted < count($this->workers)
			&& array_any($this->workers, fn(array $worker) => proc_get_status($worker[0])['running']);
	}


	/** @return array{resource, string}  process, file with its output */
	private function spawn(string $address): array
	{
		$output = (string) tempnam(sys_get_temp_dir(), 'dresscode-worker-');
		$process = @proc_open( // @ - reported as exception
			[...$this->command, '--worker', $address],
			[0 => ['pipe', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']],
			$pipes,
			$this->cwd,
		);
		if ($process === false) {
			throw new \RuntimeException('Cannot start a worker process: `' . implode(' ', $this->command) . '`');
		}

		fclose($pipes[0]);
		return [$process, $output];
	}


	/**
	 * A worker that exited with an error, one that has spent longer than the timeout on a file, or every worker
	 * gone while results are missing fails the run.
	 */
	private function checkWorkers(bool $resultsMissing): void
	{
		$running = false;
		foreach ($this->workers as [$process]) {
			$status = proc_get_status($process);
			if ($status['running']) {
				$running = true;
			} elseif ($status['exitcode'] !== 0) {
				throw new \RuntimeException("A worker exited with code {$status['exitcode']}." . $this->collectErrors());
			}
		}

		foreach ($this->inProgress as [$path, $since]) {
			if (microtime(as_float: true) - $since > $this->taskTimeout) {
				throw new \RuntimeException("A worker has spent more than $this->taskTimeout s on `$path`; a rule has most likely looped." . $this->collectErrors());
			}
		}

		if (!$running && $resultsMissing) {
			throw new \RuntimeException('The workers ended before processing every file.' . $this->collectErrors());
		}
	}


	private function collectErrors(): string
	{
		$errors = '';
		foreach ($this->workers as [, $output]) {
			$errors .= trim((string) @file_get_contents($output)); // @ - may be gone
		}

		return $errors === '' ? '' : "\n$errors";
	}


	private function stop(): void
	{
		foreach ($this->workers as [$process, $output]) {
			if (proc_get_status($process)['running']) {
				proc_terminate($process);
			}

			proc_close($process);
			@unlink($output); // @ - may be gone
		}

		$this->workers = $this->sockets = $this->inProgress = $this->buffers = [];
		$this->accepted = $this->pending = 0;
		$this->read = null;
	}
}
