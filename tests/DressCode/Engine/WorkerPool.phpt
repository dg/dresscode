<?php declare(strict_types=1);

use DressCode\Engine\WorkerPool;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return list<string>  the command line of a worker behaving as the mode says */
function fakeWorker(string $mode): array
{
	$ini = php_ini_loaded_file();
	return [getPhpCliBinary(), ...($ini === false ? [] : ['-c', $ini]), __DIR__ . '/fixtures/fake-worker.php', $mode];
}


/** @return array<string, DressCode\FileResult> */
function processWith(WorkerPool $pool): array
{
	return iterator_to_array($pool->process(['a.php', 'b.php', 'c.php'], fn(string $path) => "<?php // $path\n"));
}


test('a worker starts with OPcache off on Windows, whatever the settings it takes from the process', function () {
	$off = PHP_OS_FAMILY === 'Windows' ? ['-d', 'opcache.enable_cli=0'] : [];
	Assert::same([...(array) WorkerPool::buildPhpCommand(), ...$off], WorkerPool::buildWorkerPhpCommand());
});


test('an answer that arrives in parts is put together into one result', function () {
	$results = processWith(new WorkerPool(fakeWorker('split'), jobs: 2));
	ksort($results);
	Assert::same(['a.php', 'b.php', 'c.php'], array_keys($results));
	Assert::same("<?php // a.php\n", $results['a.php']->output);
});


test('the files the results depend on and what the analyses tell are gathered from every worker, each once', function () {
	$pool = new WorkerPool(fakeWorker('split'), jobs: 2);
	processWith($pool);
	$dependencies = $pool->getDependencies();
	sort($dependencies);
	Assert::same(['stubs/a.php.stub', 'stubs/b.php.stub', 'stubs/c.php.stub'], $dependencies);
	Assert::same(['The types go without the configuration.'], $pool->getWarnings());
});


test('with the warm-up first, the other workers start only once the first has connected', function () {
	$log = createTempDir('warm-first') . '/log.txt';
	$results = processWith(new WorkerPool(fakeWorker("log=$log"), jobs: 3, warmFirst: true));
	Assert::count(3, $results);
	$events = file($log, FILE_IGNORE_NEW_LINES) ?: [];
	// the first worker may process every file before the others connect, and those are stopped then
	Assert::same(['start', 'connect'], array_slice($events, 0, 2));
});


test('a worker stuck in the middle of an answer fails the run once it spends longer than the timeout on the file', function () {
	$started = microtime(as_float: true);
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('stall'), jobs: 1, taskTimeout: 1)),
		RuntimeException::class,
		'A worker has spent more than 1 s on `a.php`; a rule has most likely looped.%A?%',
	);
	Assert::true(microtime(as_float: true) - $started < 10);
});


test('a path that is not UTF-8 is handed out and answered as it is', function () {
	$path = "\xE9.php";
	$results = iterator_to_array(new WorkerPool(fakeWorker('split'), jobs: 1)->process([$path], fn(string $path) => "<?php\n"));
	Assert::same([$path], array_keys($results));
	Assert::same($path, $results[$path]->path);
});


test('a worker that never connects fails the run once the connect timeout passes', function () {
	$started = microtime(as_float: true);
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('silent'), jobs: 2, connectTimeout: 1, warmFirst: true)),
		RuntimeException::class,
		'A worker has not connected within 1 s.%A?%',
	);
	Assert::true(microtime(as_float: true) - $started < 10);
});


test('a worker that ends in the middle of a file fails the run, which says how it ended', function () {
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('exit=3'), jobs: 1)),
		RuntimeException::class,
		'A worker ended with code 3 while processing `a.php`.%A?%',
	);
});


test('a worker that ends before it connects fails the run, which says how it ended', function () {
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('exit-at-start=3'), jobs: 1)),
		RuntimeException::class,
		'A worker ended with code 3.%A?%',
	);
});


if (PHP_OS_FAMILY === 'Windows') {
	test('a code of Windows is written in the form it is known by', function () {
		Assert::exception(
			fn() => processWith(new WorkerPool(fakeWorker('exit=-1073741819'), jobs: 1)),
			RuntimeException::class,
			'A worker ended with code 0xC0000005 while processing `a.php`.%A?%',
		);
	});
}


if (function_exists('posix_kill')) {
	test('the signal that ended a worker is named', function () {
		Assert::exception(
			fn() => processWith(new WorkerPool(fakeWorker('kill'), jobs: 1)),
			RuntimeException::class,
			'A worker ended on signal 9 while processing `a.php`.%A?%',
		);
	});
}


test('an answer about another file of the run than the one given fails the run', function () {
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('other-path'), jobs: 1)),
		RuntimeException::class,
		'A worker sent an unexpected message.%A?%',
	);
});
