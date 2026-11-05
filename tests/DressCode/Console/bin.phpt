<?php declare(strict_types=1);

use DressCode\Engine\WorkerPool;
use Tester\{Assert, Environment};

require __DIR__ . '/../../bootstrap.php';

if (!extension_loaded('Zend OPcache')) {
	Environment::skip('OPcache is not loaded.');
}


test('the program runs with OPcache off on Windows, and leaves it alone elsewhere', function () {
	$dir = createTempDir('bin-opcache');
	$prepend = "$dir/prepend.php";
	$result = "$dir/result.txt";
	file_put_contents(
		$prepend,
		"<?php register_shutdown_function(fn() => file_put_contents('$result', ini_get('opcache.enable')));",
	);

	$process = proc_open(
		[
			...(array) WorkerPool::buildPhpCommand(),
			'-d',
			'opcache.enable_cli=1',
			'-d',
			"auto_prepend_file=$prepend",
			__DIR__ . '/../../../bin/dresscode',
			'--version',
		],
		[1 => ['pipe', 'w']],
		$pipes,
	);
	Assert::type('resource', $process);
	stream_get_contents($pipes[1]);
	Assert::same(0, proc_close($process));
	Assert::same(PHP_OS_FAMILY === 'Windows' ? '0' : '1', file_get_contents($result));
});
