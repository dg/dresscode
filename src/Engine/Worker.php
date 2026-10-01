<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use function is_array, is_string;
use const JSON_INVALID_UTF8_SUBSTITUTE, JSON_THROW_ON_ERROR;


/**
 * The worker side of a WorkerPool: connects to the parent, processes the paths it is given one by one and
 * answers each with the result as a line of JSON, until the parent closes the connection.
 * @internal
 */
final class Worker
{
	/** @return int  exit code */
	public static function run(string $address, Runner $runner, bool $fix, ?Profiler $profiler = null): int
	{
		$context = stream_context_create(['socket' => ['tcp_nodelay' => true]]); // Nagle would delay every small message by an ACK
		$socket = @stream_socket_client("tcp://$address", $errno, $error, timeout: 10, context: $context); // @ reported below
		if ($socket === false) {
			fwrite(STDERR, "Cannot connect to the parent at `$address`: $error\n");
			return 2;
		}

		$collector = new CycleCollector($profiler);
		while (($line = fgets($socket)) !== false) {
			$job = json_decode($line, associative: true);
			if (is_array($job) && ($job['finish'] ?? null) === true) { // nothing is left; the parent waits for what was measured since the last file
				fwrite($socket, json_encode(['profile' => $profiler?->takeRecords() ?? []], JSON_THROW_ON_ERROR) . "\n");
				continue;
			}

			$path = is_array($job) && is_string($job['path'] ?? null) ? base64_decode($job['path'], strict: true) : false;
			if ($path === false) {
				fwrite(STDERR, "Unexpected message from the parent: $line");
				return 2;
			}

			$result = $runner->processPath($path, $fix);
			fwrite($socket, json_encode(WorkerCodec::encode($result, $profiler?->takeRecords()), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
			$collector->afterFile(); // once the parent has the result, which then does not wait for it
		}

		fclose($socket);
		return 0;
	}
}
