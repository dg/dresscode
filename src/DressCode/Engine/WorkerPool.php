<?php declare(strict_types=1);

namespace DressCode\Engine;

use function count, is_array, is_string;


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

	/** workers that have connected so far */
	private int $accepted = 0;

	/** @var array<int, array{string, float}>  path in progress with the time it started, by socket id */
	private array $inProgress = [];

	/** @var list<array{resource, string}>  process, file with its output */
	private array $workers = [];

	/** @var list<string> */
	private array $queue = [];


	/**
	 * @param list<string> $command  the worker command line; --worker with the address is appended
	 * @param ?string $cwd  working directory of the workers
	 */
	public function __construct(
		private readonly array $command,
		private readonly int $jobs,
		private readonly ?string $cwd = null,
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
	 * @param  array<string, string>  $files  path → content
	 * @param  ?\Closure(int, array<string, float>): void  $onProgress  files done and the paths in progress
	 * @return array<string, FileResult>  by path
	 * @throws \RuntimeException  when a worker fails
	 */
	public function process(array $files, ?\Closure $onProgress = null): array
	{
		$address = $this->listen();
		$this->queue = array_keys($files);
		$results = [];
		try {
			for ($i = min($this->jobs, count($this->queue)); $i > 0; $i--) {
				$this->workers[] = $this->spawn($address);
			}

			while (count($results) < count($files)) {
				foreach ($this->await() as $stream) {
					foreach ($this->receive((int) $stream) as $data) {
						$path = is_array($data) && is_string($data['path'] ?? null) ? $data['path'] : null;
						if ($path === null || !isset($files[$path])) {
							throw new \RuntimeException('A worker sent an unexpected message.' . $this->collectErrors());
						}

						$results[$path] = FileResult::fromArray($data, $files[$path]);
					}
				}

				$this->checkWorkers(count($results) < count($files));
				if ($onProgress !== null) {
					$onProgress(count($results), $this->getInProgress());
				}
			}
		} finally {
			foreach ($this->sockets as $socket) {
				fclose($socket);
			}

			$this->closeServer();
			$this->stop();
		}

		return $results;
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


	/** @param resource $socket */
	private function connected($socket): void
	{
		$this->accepted++;
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
	 * The message the worker sent, one line for the path in progress; an ended worker with a path in progress
	 * is a failure.
	 * @return list<mixed>
	 */
	private function receive(int $id): array
	{
		$socket = $this->sockets[$id] ?? null;
		if ($socket === null) {
			return [];
		}

		$line = fgets($socket);
		if ($line === false) {
			if (isset($this->inProgress[$id])) {
				throw new \RuntimeException("A worker ended while processing {$this->inProgress[$id][0]}." . $this->collectErrors());
			}

			$this->disconnect($id);
			return [];
		}

		unset($this->inProgress[$id]);
		$this->assign($id);
		return [json_decode($line, associative: true)];
	}


	/** Hands the next path to the worker, or closes its connection when there is none. */
	private function assign(int $id): void
	{
		$path = array_shift($this->queue);
		if ($path === null) {
			$this->disconnect($id);
			return;
		}

		$this->inProgress[$id] = [$path, microtime(as_float: true)];
		fwrite($this->sockets[$id], json_encode(['path' => $path], JSON_THROW_ON_ERROR) . "\n");
	}


	/** @return array<string, float>  path in progress → the time it started */
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
		unset($this->sockets[$id], $this->inProgress[$id]);
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
			throw new \RuntimeException('Cannot start a worker process: ' . implode(' ', $this->command));
		}

		fclose($pipes[0]);
		return [$process, $output];
	}


	/** A worker that exited with an error, or every worker gone while results are missing, fails the run. */
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

		$this->workers = $this->sockets = $this->inProgress = [];
		$this->accepted = 0;
	}
}
