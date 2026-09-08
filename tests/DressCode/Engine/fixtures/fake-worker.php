<?php declare(strict_types=1);

// A worker of WorkerPool behaving as the first argument says: php fake-worker.php <split|stall|other-path> --worker <address>

$mode = $argv[1];
$socket = stream_socket_client('tcp://' . $argv[3], $errno, $error, timeout: 10);
if ($socket === false) {
	fwrite(STDERR, $error);
	exit(2);
}

while (($line = fgets($socket)) !== false) {
	$job = json_decode($line, associative: true);
	$path = is_array($job) && is_string($job['path'] ?? null) ? $job['path'] : '';
	$answer = json_encode([
		'path' => $mode === 'other-path' ? 'b.php' : $path,
		'output' => true,
		'violations' => [],
		'warnings' => [],
		'error' => null,
		'errorLine' => null,
		'passes' => 1,
		'failure' => null,
		'baselined' => [],
		'remaining' => [],
		'written' => false,
	], JSON_THROW_ON_ERROR) . "\n";

	if ($mode === 'other-path') {
		fwrite($socket, $answer);
		continue;
	}

	// a part of the line first, as a large result arrives
	fwrite($socket, substr($answer, 0, 10));
	fflush($socket);
	$mode === 'stall' ? sleep(30) : usleep(200_000);
	fwrite($socket, substr($answer, 10));
}
