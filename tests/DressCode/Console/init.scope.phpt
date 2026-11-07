<?php declare(strict_types=1);

/**
 * init measures the files of the project: which of them the scope takes, the sample of them, and what it
 * leaves out.
 */

use DressCode\Measuring\{FileSample, InProcessProbeRunner, ProbeResult, ProbeRunner, ProjectFacts, Proposal, Scope};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/init.helpers.php';


$tabbed = "<?php\n\nclass A\n{\n\tpublic function b(): string\n\t{\n\t\treturn 'x' . 'y' . 'z';\n\t}\n}\n";


test('a file that does not parse is left out of the scope, and as it may declare what the lists lack, no name is certain', function () {
	$root = createProject('unparsed', [
		'src/helpers.php' => "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n",
		'src/broken.php' => "<?php\n\nnamespace App;\n\nfunction (\n",
		'src/plain.php' => "<?php\n\nfunction (\n", // no namespace, so only the check of a standard finds it
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains('Project    3 PHP files in src', $out);
	Assert::match("%A%Excluded   src/broken.php   does not parse\n           src/plain.php    does not parse\n%A%", $out);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains("excludePaths:\n\t- src/broken.php   # does not parse\n\t- src/plain.php    # does not parse\n", $neon);
	Assert::notContains('nameResolution', $neon);
});


test('a rule failing in a file leaves it out of the measuring, which the head of the file and exit code 2 say', function () {
	$root = createProject('failing', ['src/a.php' => "<?php\n\nfunction a(): void\n{\n}\n", 'src/b.php' => "<?php\n"]);
	$runner = new class (new InProcessProbeRunner($root)) implements ProbeRunner {
		public function __construct(
			private ProbeRunner $inner,
		) {
		}


		public function run(array $probes): array
		{
			return array_map(fn(ProbeResult $result) => new ProbeResult(
				$result->places,
				$result->fixable,
				$result->left,
				$result->checkedFiles,
				$result->changedFiles,
				$result->syntaxErrors,
				['src/b.php'],
			), $this->inner->run($probes));
		}
	};
	$scope = Scope::fromAutoload($root, Scope::Extensions);
	$proposal = Proposal::measure(ProjectFacts::detect($root), $scope, $runner, ['perCs']);
	$proposal->verify();
	Assert::same(['src/b.php'], $proposal->findFailedFiles());
	Assert::match(
		"# made to measure %a%\n# not measured: a rule failed in src/b.php, so the decisions rest on the other files\n%A%",
		$proposal->toDraft()->toNeon(),
	);

	// a rule failing under every standard there is to choose from leaves none to choose
	$proposal = Proposal::measure(ProjectFacts::detect($root), Scope::fromAutoload($root, Scope::Extensions), $runner);
	Assert::true($proposal->standard->unsure);
});


test('a project without a PHP file to measure is told so, and nothing is written', function () {
	$root = createProject('empty-project', ['src/readme.txt' => 'nothing']);
	[$code, , $err] = runInit($root, ['--yes']);
	Assert::same(3, $code);
	Assert::contains('The project has no PHP file to measure in', $err);
	Assert::false(is_file("$root/dresscode.neon"));

	// over a configuration whose overrides give every file decisions of their own, that is what init says
	$root = createProject('overridden-project', [
		'src/A.php' => "<?php\n",
		'dresscode.neon' => "use: perCs\npaths: [src]\noverrides:\n\t-\n\t\tpaths: [src]\n\t\tindentation:\n\t\t\tunit: tab\n",
	]);
	[$code, $out, $err] = runInit($root);
	Assert::same([2, ''], [$code, $err]);
	Assert::contains('Compared   nothing, as an override of `dresscode.neon` gives every file it checks decisions of their own', $out);
	$data = json_decode(runInit($root, ['--format', 'json'])[1], associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(['version', 'root', 'scope'], array_keys($data));
	Assert::same(0, $data['scope']['files']);
});


test('without the usual directories the root itself is the scope', function () use ($tabbed) {
	$root = createProject('flat', ['A.php' => $tabbed]);
	[$code] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("paths: [.]\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('the sample is every k-th file of every directory, the same for the same tree, a large file among them', function () {
	$files = [];
	foreach (range(1, 400) as $i) {
		$files[sprintf('src/f%03d.php', $i)] = "<?php\n";
	}

	$files['src/f007.php'] = "<?php\n" . str_repeat('// x', 30_000);
	$root = createProject('sample', $files);
	$scope = array_keys($files);
	sort($scope, SORT_STRING);

	$limits = ['maxFiles' => FileSample::MaxFiles, 'maxBytes' => FileSample::MaxBytes];
	$sample = FileSample::pick($root, $scope, ...$limits);
	Assert::same(200, count($sample->files)); // every second of the 400
	Assert::same('src/f001.php', $sample->files[0]);
	Assert::same('src/f003.php', $sample->files[1]);
	Assert::contains('src/f007.php', $sample->files);
	Assert::same($sample->files, FileSample::pick($root, $scope, ...$limits)->files);
	Assert::same(400, count(FileSample::pick($root, $scope)->files)); // a scope this small is measured whole

	// a small directory beside a large one is sampled whole, and the large one gives the rest
	@mkdir("$root/lib"); // @ directory may already exist
	$lib = array_map(fn(int $i) => "lib/l$i.php", range(1, 10));
	foreach ($lib as $file) {
		file_put_contents("$root/$file", "<?php\n");
	}

	$sample = FileSample::pick($root, [...$lib, ...$scope], maxFiles: 60);
	Assert::same(['lib' => [10, 10], 'src' => [50, 400]], $sample->directories);

	// the budget of bytes is the cost of the measure, and it holds whatever the count allows
	$files = [];
	foreach (range(1, 30) as $i) {
		$files[sprintf('src/g%02d.php', $i)] = "<?php\n" . str_repeat('x', 90_000);
	}

	$root = createProject('sample-bytes', $files);
	$sample = FileSample::pick($root, array_keys($files), ...$limits);
	Assert::same(22, count($sample->files)); // 22 * 90 kB is the last that fits in 2 MB
	Assert::same(11, count(FileSample::pick($root, array_keys($files), FileSample::MaxFiles, 1_000_000)->files));
	Assert::same(10, count(FileSample::pick($root, array_keys($files), maxFiles: 10, maxBytes: FileSample::MaxBytes)->files)); // every third of the 30

	// where no file fits, the smallest is the sample, as none would be no evidence at all
	file_put_contents("$root/src/g07.php", "<?php\n");
	$sample = FileSample::pick($root, array_keys($files), maxBytes: 10);
	Assert::same([['src/g07.php'], ['src' => [1, 30]]], [$sample->files, $sample->directories]);

	// more directories than files the sample may have leave some of them out, the sample never growing past its limit
	$files = [];
	foreach (range(1, 12) as $i) {
		$files[sprintf('src/d%02d/a.php', $i)] = "<?php\n";
	}

	$root = createProject('sample-directories', $files);
	$sample = FileSample::pick($root, array_keys($files), maxFiles: 10, maxBytes: FileSample::MaxBytes);
	Assert::same(10, count($sample->files));
	Assert::same(['src/d01' => [0, 1], 'src/d02' => [0, 1]], array_filter($sample->directories, fn(array $counts) => $counts[0] === 0));
});


test('the probes run in-process with --jobs 1 and measure the same', function () use ($tabbed) {
	$root = createProject('jobs', ['src/A.php' => $tabbed, 'src/B.php' => str_replace('A', 'B', $tabbed), 'composer.json' => '{}']);
	$neon = fn(?int $jobs) => json_decode(
		runInit($root, ['--use', 'perCs', '--dry-run', '--format', 'json'], jobs: $jobs)[1],
		associative: true,
		flags: JSON_THROW_ON_ERROR,
	)['configuration'];
	Assert::same($neon(null), $neon(1));
});


test('a file git does not track is not measured, but the check takes it, as the first fix does', function () use ($tabbed) {
	$root = createProject('untracked', ['src/A.php' => $tabbed, 'composer.json' => '{}']);
	exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1 && git -C ' . escapeshellarg($root) . ' add -A 2>&1', $output, $exit);
	if ($exit !== 0) {
		Tester\Environment::skip('git does not run: ' . implode("\n", $output));
	}

	file_put_contents("$root/src/B.php", "<?php\n\$x = 1;\n");
	file_put_contents("$root/src/C.php", "<?php\n\$y = 2;\n");
	[$code, $out] = runInit($root, ['--use', 'perCs', '--dry-run', '--budget', '1']);
	Assert::same(0, $code);
	Assert::match(
		"%A%Project    1 PHP file in git\n           not measured: 2 files git does not track, which the first fix takes too\n%A%",
		$out,
	);
	Assert::contains('Checked    all 3 files: ', $out);
	Assert::contains('afterOpeningTag: keep   # not checked: a fix would change 2 of 3 files', $out);
});
