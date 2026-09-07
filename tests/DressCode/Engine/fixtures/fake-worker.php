<?php declare(strict_types=1);

// A worker of WorkerPool behaving as the first argument says: php fake-worker.php <split|stall|other-path|log=<file>> --worker <address>
// log=<file> writes into the file when it starts and when it connects, a while later, and then answers as split does

$mode = $argv[1];
if (str_starts_with($mode, 'log=')) {
	$log = substr($mode, 4);
	file_put_contents($log, "start\n", FILE_APPEND | LOCK_EX);
	usleep(300_000);
	file_put_contents($log, "connect\n", FILE_APPEND | LOCK_EX);
	$mode = 'split';
}

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
		'syntaxError' => null,
		'syntaxErrorLine' => null,
		'passes' => 1,
		'failure' => null,
		'failureDocs' => null,
		'baselined' => [],
		'remaining' => [],
		'written' => false,
		'cached' => false,
		'profile' => null,
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
