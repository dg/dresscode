<?php declare(strict_types=1);

use DressCode\Measuring\{InProcessProbeRunner, Probe, ProcessProbeRunner};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$root = createTempDir('probes');
mkdir("$root/src");
file_put_contents("$root/composer.json", '{"require": {"php": ">=8.1"}}');
file_put_contents("$root/src/a.php", "<?php\n\$a = \"x\";\n\$b = \"y\";\n");
file_put_contents("$root/src/b.php", "<?php\n\$c = 'z';\n");
file_put_contents("$root/src/broken.php", "<?php\nfunction (\n");
$probes = [
	'single' => new Probe(['paths' => ['src/a.php', 'src/b.php', 'src/broken.php'], 'literals' => ['quotes' => 'single']]),
	'double' => new Probe(['paths' => ['src/a.php', 'src/b.php'], 'literals' => ['quotes' => 'double']]),
	// the quotes under the standard, which also reports the missing strict types
	'narrowed' => new Probe(['paths' => ['src/a.php'], 'use' => ['nette']], ['literals.quotes']),
];


test('a probe counts by decision what disagrees with its configuration, and what a fix would change', function () use ($root, $probes) {
	$progress = [];
	$results = new InProcessProbeRunner($root, function (int $done, int $all) use (&$progress) {
		$progress[] = "$done/$all";
	})->run($probes);
	Assert::same(['1/3', '2/3', '3/3'], $progress);
	Assert::same(['literals.quotes' => ['src/a.php' => 2]], $results['single']->places);
	Assert::same(['literals.quotes' => ['src/a.php' => true]], $results['single']->fixable);
	Assert::same(['literals.quotes'], $results['single']->findResidue());
	Assert::same([3, 1], [$results['single']->checkedFiles, $results['single']->changedFiles]);
	Assert::same(['src/broken.php'], $results['single']->syntaxErrors);
	Assert::same(
		[1, 1],
		[$results['double']->countFiles('literals.quotes'), array_sum($results['double']->places['literals.quotes'] ?? [])],
	);
	Assert::same(['literals.quotes' => ['src/a.php' => 2]], $results['narrowed']->places);
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
	Assert::same(['0/3', '3/3'], [$progress[0], end($progress)]);

	Assert::exception(
		fn() => $runner->run(['bad' => new Probe(['literals' => ['quotes' => 'curly']])]),
		RuntimeException::class,
		'A check of the measuring ended with exit code 3: %a%',
	);
});


test('a probe in which a rule fails in a file counts that file, while a probe that cannot run ends the measuring', function () use ($root) {
	$script = createTempDir('probe-script') . '/check.php';
	$report = json_encode(
		['files' => [['path' => 'src/a.php', 'violations' => [], 'changed' => false, 'syntaxError' => null, 'failure' => 'boom']]],
	);
	file_put_contents($script, "<?php\necho '$report';\nexit((int) \$argv[1]);\n");
	$run = fn(int $exit) => new ProcessProbeRunner($root, [getPhpCliBinary(), $script, (string) $exit], processors: 1)
		->run(['a' => new Probe([])]);

	Assert::same(['src/a.php'], $run(2)['a']->failed);
	Assert::exception(fn() => $run(3), RuntimeException::class, '%a% exit code 3: %a%');
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
