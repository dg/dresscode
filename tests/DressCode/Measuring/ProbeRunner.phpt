<?php declare(strict_types=1);

use DressCode\ConfigurationException;
use DressCode\Measuring\{InProcessProbeRunner, ProcessProbeRunner};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$root = createTempDir('probes');
mkdir("$root/src");
file_put_contents("$root/composer.json", '{"require": {"php": ">=8.1"}}');
file_put_contents("$root/src/a.php", "<?php\n\$a = \"x\";\n\$b = \"y\";\n");
file_put_contents("$root/src/b.php", "<?php\n\$c = 'z';\n");
file_put_contents("$root/src/broken.php", "<?php\nfunction (\n");
$probes = [
	'single' => ['paths' => ['src/a.php', 'src/b.php', 'src/broken.php'], 'literals' => ['quotes' => 'single']],
	'double' => ['paths' => ['src/a.php', 'src/b.php'], 'literals' => ['quotes' => 'double']],
];


test('a probe counts by decision what disagrees with its configuration, and what a fix would change', function () use ($root, $probes) {
	$progress = [];
	$results = new InProcessProbeRunner($root, function (int $done, int $all) use (&$progress) {
		$progress[] = "$done/$all";
	})->run($probes);
	Assert::same(['1/2', '2/2'], $progress);
	Assert::same(['literals.quotes' => ['src/a.php' => 2]], $results['single']->places);
	Assert::same(['literals.quotes' => ['src/a.php' => true]], $results['single']->fixable);
	Assert::same(['literals.quotes'], $results['single']->findResidue());
	Assert::same(1, $results['single']->changedFiles);
	Assert::same(['src/broken.php'], $results['single']->syntaxErrors);
	Assert::same([1, 1], [$results['double']->countFiles('literals.quotes'), $results['double']->countPlaces('literals.quotes')]);
});


test('the probes run as processes of check count the same as in the process, the root being the project', function () use ($root, $probes) {
	$binary = getPhpCliBinary();
	$progress = [];
	$runner = new ProcessProbeRunner(
		$root,
		[$binary, __DIR__ . '/../../../bin/dresscode'],
		2,
		function (int $done, int $all) use (&$progress) {
			$progress[] = "$done/$all";
		},
	);
	$inProcess = new InProcessProbeRunner($root)->run($probes);
	Assert::equal($inProcess, $runner->run($probes));
	// told at every look while the probes run, the last time when all are done
	Assert::same(['0/2', '2/2'], [$progress[0], end($progress)]);

	Assert::exception(
		fn() => $runner->run(['bad' => ['literals' => ['quotes' => 'curly']]]),
		ConfigurationException::class,
		'A check of the measuring ended with exit code 3: %a%',
	);
});


test('a probe in which a rule fails in a file counts that file, while a probe that cannot run ends the measuring', function () use ($root) {
	$script = createTempDir('probe-script') . '/check.php';
	$report = json_encode(
		['files' => [['path' => 'src/a.php', 'violations' => [], 'changed' => false, 'syntaxError' => null, 'failure' => 'boom']]],
	);
	file_put_contents($script, "<?php\necho '$report';\nexit((int) getenv('PROBE_EXIT'));\n");
	$runner = new ProcessProbeRunner($root, [getPhpCliBinary(), $script], processors: 1);

	putenv('PROBE_EXIT=2');
	try {
		Assert::same(['src/a.php'], $runner->run(['a' => []])['a']->failed);
		putenv('PROBE_EXIT=3');
		Assert::exception(fn() => $runner->run(['a' => []]), ConfigurationException::class, '%a% exit code 3: %a%');
	} finally {
		putenv('PROBE_EXIT');
	}
});


test('a process of a probe starts no probes of its own, so the processes never grow beyond one level', function () use ($root, $probes) {
	putenv('DRESSCODE_PROBE=1');
	try {
		Assert::exception(
			fn() => ProcessProbeRunner::create($root)->run($probes),
			LogicException::class,
			'A probe of `dresscode init` starts no probes of its own.',
		);
	} finally {
		putenv('DRESSCODE_PROBE');
	}
});


test('the probes are run by the script of this DressCode, whoever calls the runner', function () use ($root) {
	$command = (fn() => $this->command)->call(ProcessProbeRunner::create($root));
	Assert::same(realpath(__DIR__ . '/../../../bin/dresscode'), realpath((string) end($command)));
});
