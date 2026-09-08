<?php declare(strict_types=1);

use DressCode\Engine\WorkerPool;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return list<string>  the command line of a worker behaving as the mode says */
function fakeWorker(string $mode): array
{
	$ini = php_ini_loaded_file();
	return [PHP_BINARY, ...($ini === false ? [] : ['-c', $ini]), __DIR__ . '/fixtures/fake-worker.php', $mode];
}


/** @return array<string, DressCode\FileResult> */
function processWith(WorkerPool $pool): array
{
	return iterator_to_array($pool->process(['a.php', 'b.php', 'c.php'], fn(string $path) => "<?php // $path\n"));
}


test('an answer that arrives in parts is put together into one result', function () {
	$results = processWith(new WorkerPool(fakeWorker('split'), jobs: 2));
	ksort($results);
	Assert::same(['a.php', 'b.php', 'c.php'], array_keys($results));
	Assert::same("<?php // a.php\n", $results['a.php']->output);
});


test('a worker stuck in the middle of an answer fails the run once it spends longer than the timeout on the file', function () {
	$started = microtime(as_float: true);
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('stall'), jobs: 1, taskTimeout: 1)),
		RuntimeException::class,
		'A worker has spent more than 1 s on a.php; a rule has most likely looped.%A?%',
	);
	Assert::true(microtime(as_float: true) - $started < 10);
});


test('an answer about another file of the run than the one given fails the run', function () {
	Assert::exception(
		fn() => processWith(new WorkerPool(fakeWorker('other-path'), jobs: 1)),
		RuntimeException::class,
		'A worker sent an unexpected message.%A?%',
	);
});
