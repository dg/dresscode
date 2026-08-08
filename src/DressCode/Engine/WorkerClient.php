<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Engine;
use function is_array, is_string;


/**
 * The worker side of a WorkerPool: connects to the parent, processes the paths it is given one by one and
 * answers each with the result as a line of JSON, until the parent closes the connection.
 * @internal
 */
final class WorkerClient
{
	/** @return int  exit code */
	public static function serve(string $address, Engine $engine, bool $fix): int
	{
		$context = stream_context_create(['socket' => ['tcp_nodelay' => true]]); // Nagle would delay every small message by an ACK
		$socket = @stream_socket_client("tcp://$address", $errno, $error, timeout: 10, context: $context); // @ - reported below
		if ($socket === false) {
			fwrite(STDERR, "Cannot connect to the parent at $address: $error\n");
			return 2;
		}

		while (($line = fgets($socket)) !== false) {
			$job = json_decode($line, associative: true);
			$path = is_array($job) && is_string($job['path'] ?? null) ? $job['path'] : null;
			if ($path === null) {
				fwrite(STDERR, "Unexpected message from the parent: $line");
				return 2;
			}

			$result = $engine->processPath($path, $fix);
			fwrite($socket, json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
		}

		fclose($socket);
		return 0;
	}
}
