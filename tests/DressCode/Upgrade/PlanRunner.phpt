<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Upgrade\{
	Blocker, Blockers, ComposerStep, FixStep, ManualStep, PackageManager, Plan, PlanRunner,
	Project, RunOutcome, RunState, RunStop, Target, TestResults, TestRunner, TestStatus,
};
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** A project on PHP ^8.0 whose tests pass while the code writes no `?int`, which the bridge writes. */
function createSteppedProject(string $name): string
{
	$root = createTempDir($name);
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\ncacheDir: cache\n");
	file_put_contents("$root/src/Code.php", "<?php\r\n\r\nfunction run(int \$count = null): void\r\n{\r\n}\r\n");
	file_put_contents("$root/tests.php", <<<'XX'
		<?php
		$passed = !str_contains(file_get_contents(__DIR__ . '/src/Code.php'), '?int');
		file_put_contents($argv[1], '<testsuites><testsuite><testcase classname="' . __DIR__ . '/tests/Code.phpt" name="run"'
			. ($passed ? '/>' : '><failure/></testcase>') . '<testcase classname="Other" name="other"/></testsuite></testsuites>');
		XX);
	return $root;
}


function createSteppedRunProject(string $root): Project
{
	return new Project(Config\Loader::loadFile("$root/dresscode.neon"), $root, "$root/dresscode.neon");
}


/** Composer as a test scripts it: a raise writes its constraints into `composer.json`, an update changes what it is given until it ran. */
function createSteppedPlan(?string $testCommand = null): Plan
{
	return new Plan(
		[Target::php('8.4')],
		[
			new FixStep('works on the versions allowed today', ['upgrading.php.implicitNullable']),
			new ComposerStep('raise php to ^8.4', ['php' => '^8.4']),
		],
		'1.0',
		testCommand: $testCommand,
	);
}


test('the results of the tests are read from JUnit as PHPUnit and Nette Tester write it, a test by its class and name', function () {
	$results = TestResults::fromJunit(<<<'XX'
		<testsuites>
			<testsuite name="App">
				<testcase class="App\Tests\MailTest" classname="App.Tests.MailTest" name="testSend with data set #0"/>
				<testcase classname="App\Tests\MailTest" name="testQueue"><failure>broken</failure></testcase>
				<testcase classname="W:/acme/tests/Mail.phpt" name="W:/acme/tests/Mail.phpt"><error/></testcase>
			</testsuite>
		</testsuites>
		XX, 'W:/acme') ?? throw new RuntimeException;

	Assert::same([
		'App.Tests.MailTest::testSend with data set #0' => TestStatus::Passed,
		'App/Tests/MailTest::testQueue' => TestStatus::Failed,
		'tests/Mail.phpt::W:/acme/tests/Mail.phpt' => TestStatus::Error,
	], $results->tests);
	// what failed before, or passes now, is no new failure
	Assert::same(
		['tests/Mail.phpt::W:/acme/tests/Mail.phpt (error)'],
		$results->findNewFailures(new TestResults(['App/Tests/MailTest::testQueue' => TestStatus::Failed])),
	);

	// the results a run keeps are read back as they were written, those of another shape not at all
	$file = createTempDir('test-results') . '/results.json';
	$results->save($file);
	Assert::same($results->tests, TestResults::fromFile($file)?->tests);
	file_put_contents($file, '{"A::one": true}');
	Assert::null(TestResults::fromFile($file));
	Assert::null(TestResults::fromJunit('no xml', 'W:/acme'));
	Assert::null(TestResults::fromJunit('<html/>', 'W:/acme'));
});


test('a test gone, skipped now or failing under one of its repeated identities is a new failure', function () {
	$baseline = new TestResults(['A::one' => TestStatus::Passed, 'A::two' => TestStatus::Passed, 'A::three' => TestStatus::Failed]);
	Assert::same(['A::one (gone)', 'A::two (gone)'], TestResults::fromJunit('<testsuites/>', '')?->findNewFailures($baseline));

	$results = TestResults::fromJunit(<<<'XX'
		<testsuites>
			<testcase classname="A" name="one"><skipped/></testcase>
			<testcase classname="A" name="two"/>
			<testcase classname="A" name="two"><failure/></testcase>
		</testsuites>
		XX, '');
	Assert::same(['A::one (skipped)', 'A::two'], $results?->findNewFailures($baseline));
});


test('a run says by its exit code whether it is done, leaves work for a human, is incomplete or waits outside DressCode', function () {
	$codes = [];
	foreach (RunStop::cases() as $stop) {
		$codes[$stop->name] = new RunOutcome($stop)->getExitCode();
	}

	Assert::same([
		'Done' => 0,
		'Composer' => 4,
		'Manual' => 4,
		'Tests' => 1,
		'Unprocessed' => 2,
		'Remaining' => 1,
		'Interrupted' => 1,
	], $codes);
	// tests that do not run before the first step leave the run incomplete, a crash after a step goes back as a failure
	Assert::same(2, new RunOutcome(RunStop::Tests, crash: 'it wrote no results')->getExitCode());
	Assert::same(1, new RunOutcome(RunStop::Tests, 1, crash: 'it wrote no results')->getExitCode());
});


test('a fix step runs over the project as it is and stops before the raise of Composer, which it waits for', function () {
	$root = createSteppedProject('steps-blocked');
	$generated = "<?php\n// @generated\nfunction gen(int \$count = null): void\n{\n}\n";
	file_put_contents("$root/src/Generated.php", $generated);
	$runner = new PlanRunner(createSteppedRunProject($root));

	Assert::equal(
		new RunOutcome(RunStop::Composer, 2, [1 => ['upgrading.php.implicitNullable' => ['src/Code.php']]]),
		$runner->run(createSteppedPlan()),
	);
	Assert::same("<?php\r\n\r\nfunction run(?int \$count = null): void\r\n{\r\n}\r\n", file_get_contents("$root/src/Code.php"));
	Assert::same($generated, file_get_contents("$root/src/Generated.php")); // left out as the estimate leaves it out

	// raised, the run goes on from the code as it is, the fix left with nothing to do
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4"}}');
	Assert::equal(new RunOutcome(RunStop::Done, null, [1 => ['upgrading.php.implicitNullable' => []]]), $runner->run(createSteppedPlan()));
});


test('a step whose tests newly fail goes back byte for byte, and a command writing no results is refused', function () {
	$root = createSteppedProject('steps-failed');
	$code = file_get_contents("$root/src/Code.php");
	$runner = new PlanRunner(createSteppedRunProject($root));
	$php = escapeshellarg(getPhpCliBinary());

	$outcome = $runner->run(createSteppedPlan("$php tests.php {junit}"));
	// a test of Nette Tester is named by its file within the root
	Assert::same([RunStop::Tests, 1, ['tests/Code.phpt::run']], [$outcome->stop, $outcome->step, $outcome->newFailures]);
	Assert::same($code, file_get_contents("$root/src/Code.php"));
	// what the tests were compared with is said, a test failing before the first step being no regression
	Assert::same([2, 0], [count($outcome->baseline->tests ?? []), $outcome->baseline?->countNotPassed()]);
	// and a step sent back applied nothing
	Assert::same([], $outcome->applied);
	Assert::match(
		"Tests      compared with their run before the first step, %d%-%d%-%d% %d%:%d%, where 0 of 2 did not pass\n%A%",
		new DressCode\Console\RunOutcomePrinter(createSteppedPlan(), $outcome)->print(new Nette\CommandLine\Console),
	);

	$outcome = new PlanRunner(new Project(new Config(paths: ['src'], cacheDir: 'other'), $root, "$root/dresscode.neon"))->run(
		createSteppedPlan("$php -r 1;"),
	);
	Assert::same([RunStop::Tests, null, []], [$outcome->stop, $outcome->step, $outcome->applied]);
	Assert::match(
		'Failed     the tests of `%a%` do not run before the first step, as the command of the tests wrote no JUnit report where `{junit}` stands, so nothing was changed',
		new DressCode\Console\RunOutcomePrinter(createSteppedPlan(), $outcome)->print(new Nette\CommandLine\Console),
	);

	// a report of no test is no result, nor is an exit code no runner ends with for its failing tests
	file_put_contents("$root/report.php", <<<'XX'
		<?php
		$case = $argv[2] === 'none' ? '' : '<testcase classname="A" name="a"><failure/></testcase>';
		file_put_contents($argv[1], "<testsuites>$case</testsuites>");
		exit((int) $argv[3]);
		XX);
	$report = fn(string $cases, int $exit) => new DressCode\Upgrade\ProcessTestRunner()->run(
		escapeshellarg(getPhpCliBinary()) . " report.php {junit} $cases $exit",
		$root,
	)->crash;
	Assert::same('the report of the tests holds no test', $report('none', 0));
	Assert::same('the command of the tests ended with exit code 255', $report('failing', 255));
	Assert::null($report('failing', 1));
});


test('a command of the tests that crashes after writing a report of passing tests fails the step, and the baseline too', function () {
	$root = createSteppedProject('steps-crashed');
	$code = file_get_contents("$root/src/Code.php");
	file_put_contents("$root/crash.php", <<<'XX'
		<?php
		file_put_contents($argv[1], '<testsuite><testcase classname="A" name="test"/></testsuite>');
		if (str_contains(file_get_contents(__DIR__ . '/src/Code.php'), '?int') || isset($argv[2])) {
			echo "Fatal error: shutdown\n";
			exit(7);
		}
		XX);
	$php = escapeshellarg(getPhpCliBinary());

	$outcome = new PlanRunner(createSteppedRunProject($root))->run(createSteppedPlan("$php crash.php {junit}"));
	Assert::same([RunStop::Tests, 1, []], [$outcome->stop, $outcome->step, $outcome->newFailures]);
	Assert::same('the command of the tests ended with exit code 7: Fatal error: shutdown', $outcome->crash);
	Assert::same($code, file_get_contents("$root/src/Code.php"));

	$outcome = new PlanRunner(new Project(new Config(paths: ['src'], cacheDir: 'other'), $root, "$root/dresscode.neon"))->run(
		createSteppedPlan("$php crash.php {junit} always"),
	);
	Assert::match(
		'the tests of `%a%` do not run before the first step, as the command of the tests ended with exit code 7: Fatal error: shutdown',
		(string) $outcome->crash,
	);
});


test('a run interrupted in a step stops the next one before it runs anything, unless it is told to discard what was kept', function () {
	$root = createSteppedProject('steps-interrupted');
	$project = createSteppedRunProject($root);
	new RunState($project)->snapshots->record(1, 'src/Code.php', 'before', 'after');
	$runner = new PlanRunner($project);

	Assert::equal(new RunOutcome(RunStop::Interrupted, 1, interrupted: ['src/Code.php']), $runner->run(createSteppedPlan()));
	Assert::same([1 => ['src/Code.php']], new RunState($project)->snapshots->findInterrupted());
	Assert::same(RunStop::Composer, $runner->run(createSteppedPlan(), discard: true)->stop);
	Assert::same(RunStop::Composer, $runner->run(createSteppedPlan())->stop);

	// snapshots that cannot be read stop the run all the same, with the files unknown
	FileSystem::write(new RunState($project)->directory . '/snapshots/2/index.json', '{broken');
	Assert::equal(new RunOutcome(RunStop::Interrupted, 2), $runner->run(createSteppedPlan()));
});


test('a run is refused while another one works in the project, and going back forgets a file its step did not write yet', function () {
	$root = createSteppedProject('steps-locked');
	$project = createSteppedRunProject($root);
	$state = new RunState($project);
	Assert::exception(
		fn() => $state->runExclusively(fn() => new PlanRunner($project)->run(createSteppedPlan())),
		DressCode\ConfigurationException::class,
		'Another run of the upgrade works in the project right now; wait until it ends.',
	);

	$code = (string) file_get_contents("$root/src/Code.php");
	$state->snapshots->record(1, 'src/Code.php', $code, 'after');
	Assert::same([], $state->snapshots->restore(1, $root));
	Assert::same($code, file_get_contents("$root/src/Code.php"));
	Assert::same([], $state->snapshots->findInterrupted());
});


test('a file the fix cannot process stops the run after its step, and going back leaves a file changed since as it is', function () {
	$root = createSteppedProject('steps-errors');
	file_put_contents("$root/src/Broken.php", "<?php function {\n");
	$outcome = new PlanRunner(createSteppedRunProject($root))->run(createSteppedPlan());
	Assert::same([RunStop::Unprocessed, 1], [$outcome->stop, $outcome->step]);
	Assert::match('Syntax error: %a%', $outcome->unprocessed['src/Broken.php']);
	Assert::contains('?int', (string) file_get_contents("$root/src/Code.php"));

	// the tests change the file the step wrote, as a user editing it while they run would
	$root = createSteppedProject('steps-conflict');
	file_put_contents("$root/edit.php", <<<'XX'
		<?php
		if (str_contains(file_get_contents(__DIR__ . '/src/Code.php'), '?int')) {
			file_put_contents(__DIR__ . '/src/Code.php', "// edited\n", FILE_APPEND);
		}
		require __DIR__ . '/tests.php';
		XX);
	$outcome = new PlanRunner(createSteppedRunProject($root))->run(
		createSteppedPlan(escapeshellarg(getPhpCliBinary()) . ' edit.php {junit}'),
	);
	Assert::same(RunStop::Tests, $outcome->stop);
	Assert::match(
		'The file changed after step 1 wrote it, so it is not written back; its text before the step is kept in `%a%`.',
		$outcome->unprocessed['src/Code.php'],
	);
	Assert::contains("// edited\n", (string) file_get_contents("$root/src/Code.php"));
	// the text before the step stays until the user decides, as for a step that did not finish
	$runner = new PlanRunner(createSteppedRunProject($root));
	Assert::equal(new RunOutcome(RunStop::Interrupted, 1, interrupted: ['src/Code.php']), $runner->run(createSteppedPlan()));
});


test('a fix resolves the names with what the namespaces declare, as the estimate does', function () {
	$root = createSteppedProject('steps-names');
	file_put_contents("$root/src/Close.php", "<?php\n\nnamespace App;\n\nfunction close(\$handle): void\n{\n\tcurl_close(\$handle);\n}\n");
	$plan = new Plan([Target::php('8.4')], [new FixStep('bridge', ['php'])], '1.0');
	$runner = new PlanRunner(createSteppedRunProject($root));

	Assert::same(['src/Close.php'], $runner->run($plan)->applied[1]['php']);
	Assert::notContains('curl_close', (string) file_get_contents("$root/src/Close.php"));
});


test('a step the plan does not run is refused', function () {
	$root = createSteppedProject('steps-left');
	$plan = new Plan(
		[Target::php('8.4')],
		[new FixStep('bridge', ['php']), new FixStep('later', ['php'], skip: true)],
		'1.0',
	);
	$runner = new PlanRunner(createSteppedRunProject($root));

	Assert::exception(
		fn() => $runner->run($plan, singleStep: 3),
		DressCode\ConfigurationException::class,
		'The plan has no step 3; it has 2.',
	);
	Assert::exception(
		fn() => $runner->run($plan, singleStep: 2),
		DressCode\ConfigurationException::class,
		'Step 2 of the plan is left out with `skip: true`, so it is not run.',
	);
});


test('a step of Composer that updates is done once the version installed is one it allows, not the constraint alone', function () {
	$root = createSteppedProject('steps-installed');
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0", "acme/mail": "^4.0"}}');
	$installed = fn(string $version) => FileSystem::write(
		"$root/vendor/composer/installed.json",
		json_encode(['packages' => [['name' => 'acme/mail', 'version_normalized' => $version]]], JSON_THROW_ON_ERROR),
	);
	$plan = new Plan(
		[new Target('acme/mail', '4.0')],
		[new ComposerStep('raise acme/mail to ^4.0', ['acme/mail' => '^4.0'])],
		'1.0',
	);
	$runner = new PlanRunner(createSteppedRunProject($root));

	$installed('3.2.0.0');
	Assert::same(RunStop::Composer, $runner->run($plan)->stop);
	$installed('4.0.0.0');
	// the plan is done once the targets find nothing in the whole scope, which no step of this one fixes
	Assert::equal(
		new RunOutcome(RunStop::Remaining, remaining: ['src/Code.php']),
		$runner->run($plan),
	);
	file_put_contents("$root/src/Code.php", "<?php\n\nfunction run(?int \$count = null): void\n{\n}\n");
	Assert::same(RunStop::Done, $runner->run($plan)->stop);

	// the lock holds what an install gives, so it must allow the version too
	$lock = fn(string $version) => file_put_contents(
		"$root/composer.lock",
		json_encode(['packages' => [['name' => 'acme/mail', 'version' => $version]], 'packages-dev' => []], JSON_THROW_ON_ERROR),
	);
	$lock('v3.2.0');
	Assert::same(RunStop::Composer, $runner->run($plan)->stop);
	$lock('v4.0.0');
	Assert::same(RunStop::Done, $runner->run($plan)->stop);
});


test('a step raising PHP is done once config.platform.php allows the version too, which Composer resolves for', function () {
	$root = createSteppedProject('steps-platform');
	$composer = fn(string $platform) => file_put_contents(
		"$root/composer.json",
		json_encode(['require' => ['php' => '^8.4'], 'config' => ['platform' => ['php' => $platform]]], JSON_THROW_ON_ERROR),
	);
	$plan = new Plan([Target::php('8.4')], [new ComposerStep('raise php to ^8.4', ['php' => '^8.4'], platform: '8.4')], '1.0');
	$runner = new PlanRunner(createSteppedRunProject($root));
	file_put_contents("$root/src/Code.php", "<?php\n\nfunction run(?int \$count = null): void\n{\n}\n");

	$composer('8.0');
	Assert::same(RunStop::Composer, $runner->run($plan)->stop);
	$composer('8.4');
	Assert::same(RunStop::Done, $runner->run($plan)->stop);
});


test('a manual step passes once Composer says nothing of the lock refuses its target', function () {
	$root = createSteppedProject('steps-manual');
	file_put_contents("$root/src/Code.php", "<?php\n\nfunction run(?int \$count = null): void\n{\n}\n");
	file_put_contents("$root/composer.json", '{"name": "acme/app", "require": {"php": "^8.0", "acme/shop": "^1.0", "acme/mail": "^2.0"}}');
	$lock = fn(string $constraint) => file_put_contents("$root/composer.lock", json_encode([
		'packages' => [
			['name' => 'acme/shop', 'version' => '1.2.0', 'require' => ['acme/mail' => $constraint]],
			['name' => 'acme/mail', 'version' => '2.1.0'],
		],
		'packages-dev' => [],
	], JSON_THROW_ON_ERROR));
	$target = new Target('acme/mail', '3.0');
	$plan = new Plan([$target], [new ManualStep('make way for acme/mail 3.0: acme/shop', $target)], '1.0');
	$runner = new PlanRunner(createSteppedRunProject($root));

	$lock('^2.0');
	$outcome = $runner->run($plan);
	Assert::same([RunStop::Manual, 1], [$outcome->stop, $outcome->step]);
	Assert::same(['acme/shop'], array_map(fn(Blocker $blocker) => $blocker->package, $outcome->blockers->packages ?? []));

	$lock('^2.0 || ^3.0'); // the project itself refusing it is left out
	Assert::same(RunStop::Done, $runner->run($plan)->stop);

	// without a lock nothing tells, so the step waits as for an answer Composer did not give
	unlink("$root/composer.lock");
	$outcome = $runner->run($plan);
	Assert::same([RunStop::Manual, 'the project has no `composer.lock` to ask'], [$outcome->stop, $outcome->blockers?->unknown]);
});


test('Composer and the tests are boundaries a test replaces, to fail exactly where it wants', function () {
	$root = createSteppedProject('steps-boundaries');
	$composer = new class implements PackageManager {
		public function findBlockers(Target $target): Blockers
		{
			return new Blockers($target, unknown: 'Composer did not answer within 60 s');
		}
	};
	$tests = new class implements TestRunner {
		public function run(string $command, string $root): TestResults
		{
			return new TestResults(['A::a' => TestStatus::Passed], crash: 'the command of the tests ended with exit code 255');
		}
	};
	$project = createSteppedRunProject($root);
	$target = new Target('acme/mail', '3.0');

	$runner = new PlanRunner($project, composer: $composer);
	$outcome = $runner->run(new Plan([$target], [new ManualStep('make way for acme/mail 3.0', $target)], '1.0'));
	Assert::same([RunStop::Manual, 'Composer did not answer within 60 s'], [$outcome->stop, $outcome->blockers?->unknown]);

	Assert::same(
		'the tests of `tests {junit}` do not run before the first step, as the command of the tests ended with exit code 255',
		new PlanRunner($project, tests: $tests)->run(createSteppedPlan('tests {junit}'))->crash,
	);
});
