<?php declare(strict_types=1);

use DressCode\Engine\Profiler;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('what a worker hands over adds up to what one process would measure', function () {
	$worker = new Profiler;
	$worker->addPhase('parse', 10);
	$worker->addRule('a/rule', 5, mutated: true);
	$worker->addRule('a/rule', 7, mutated: false);
	$worker->addClaim('a/gap', 3);
	$worker->addFile(['path' => 'x.php', 'size' => 1, 'time' => 30]);
	$records = $worker->takeRecords();

	$worker->addPhase('parse', 20);
	$worker->addFile(['path' => 'y.php', 'size' => 1, 'time' => 40]);
	$second = $worker->takeRecords();
	Assert::same(['parse' => [20, 1]], $second['phases']); // taken records are forgotten

	$parent = new Profiler;
	$parent->merge(json_decode(json_encode($records), true)); // as it travels between processes
	$parent->merge(json_decode(json_encode($second), true));
	$profile = $parent->toArray();

	Assert::same(Profiler::Schema, $profile['schema']);
	Assert::same(2, $profile['files']);
	Assert::same(['parse' => ['time' => 30, 'calls' => 2]], $profile['phases']);
	Assert::same(['a/rule' => ['time' => 12, 'calls' => 2, 'mutating' => 1]], $profile['rules']);
	Assert::same(['a/gap' => ['time' => 3, 'calls' => 1]], $profile['claims']);
	Assert::same(['y.php', 'x.php'], array_column($profile['slowestFiles'], 'path'));
});


test('the most expensive come first, the slowest files in detail and every file in short', function () {
	$profiler = new Profiler;
	$profiler->addRule('cheap', 1, mutated: false);
	$profiler->addRule('dear', 100, mutated: false);
	for ($i = 1; $i <= 120; $i++) {
		$profiler->addFile(['path' => "$i.php", 'size' => $i, 'time' => $i]);
	}

	$profile = $profiler->toArray();
	Assert::same(['dear', 'cheap'], array_keys($profile['rules']));
	Assert::same(120, $profile['files']);
	Assert::count(50, $profile['slowestFiles']);
	Assert::same('120.php', $profile['slowestFiles'][0]['path']);
	Assert::same('71.php', $profile['slowestFiles'][49]['path']);
	Assert::count(120, $profile['fileTimes']); // every file, for a sample to be drawn from
	Assert::same(['1.php', '10.php', '100.php'], array_slice(array_keys($profile['fileTimes']), 0, 3));
});
