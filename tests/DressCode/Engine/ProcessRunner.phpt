<?php declare(strict_types=1);

use DressCode\Engine\ProcessRunner;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function isProcessRunning(int $pid): bool
{
	if (PHP_OS_FAMILY === 'Windows') {
		exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL", $output);
		return str_contains(implode("\n", $output), (string) $pid);
	}

	exec("kill -0 $pid 2>/dev/null", result_code: $exit);
	return $exit === 0;
}


test('a command not ending in time is ended with what it started', function () {
	$dir = createTempDir('process-runner-tree');
	file_put_contents("$dir/parent.php", <<<'XX'
		<?php
		$child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [], $pipes);
		file_put_contents(__DIR__ . '/child.pid', proc_get_status($child)['pid']);
		sleep(60);
		XX);

	[$exit] = ProcessRunner::run([getPhpCliBinary(), 'parent.php'], $dir, 3);
	Assert::null($exit);
	$pid = (int) file_get_contents("$dir/child.pid");
	Assert::true($pid > 0);
	usleep(500_000);
	Assert::false(isProcessRunning($pid));
});
