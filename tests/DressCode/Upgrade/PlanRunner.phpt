<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Engine\ProcessRunner;
use DressCode\Upgrade\{
	Blocker, Blockers, ComposerException, ComposerStep, DependencyStep, FixStep, ManualStep, PackageManager, Plan, PlanRunner, Postponement,
	Project, RunOutcome, RunState, RunStop, Target, Task, TestResults, TestRunner, TestStatus, UpdateStep,
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
final class PlanRunnerComposer implements PackageManager
{
	public int $updates = 0;


	public function __construct(
		private string $root,
		/** @var ?Closure(array<string, string>): void  what a raise does instead */
		private ?Closure $onRequire = null,
		/** @var list<string>  what an update changes, until it ran */
		private array $updateChanges = [],
		/** @var ?Closure(): void  what an install does */
		private ?Closure $onInstall = null,
	) {
	}


	public function findBlockers(Target $target): Blockers
	{
		return new Blockers($target, unknown: 'not asked');
	}


	public function previewRequire(array $constraints, bool $update = true, ?string $platform = null): array
	{
		return [];
	}


	public function require(array $constraints, bool $update = true, ?string $platform = null): void
	{
		$this->onRequire === null
			? file_put_contents("$this->root/composer.json", json_encode(['require' => $constraints], JSON_THROW_ON_ERROR))
			: ($this->onRequire)($constraints);
	}


	public function previewUpdate(): array
	{
		return $this->updates === 0 ? $this->updateChanges : [];
	}


	public function update(): void
	{
		$this->updates++;
	}


	public function install(): void
	{
		$this->onInstall?->__invoke();
	}
}


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
		'Raised' => 4,
		'Manual' => 4,
		'Tasks' => 1,
		'Tests' => 1,
		'Unprocessed' => 2,
		'Remaining' => 1,
		'Interrupted' => 1,
		'Commit' => 2,
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


test('a raise no fix follows is tested after it all the same, its dependencies staying raised where the tests fail', function () {
	$root = createSteppedProject('steps-raise-tested');
	file_put_contents("$root/tests.php", <<<'XX'
		<?php
		$passed = !str_contains(file_get_contents(__DIR__ . '/composer.json'), '^8.4');
		file_put_contents(__DIR__ . '/runs.txt', 'x', FILE_APPEND);
		file_put_contents($argv[1], '<testsuites><testsuite><testcase classname="Raise" name="raise"' . ($passed ? '/>' : '><failure/></testcase>') . '</testsuite></testsuites>');
		XX);
	$plan = new Plan(
		[Target::php('8.4')],
		[new ComposerStep('raise php to ^8.4', ['php' => '^8.4'])],
		'1.0',
		testCommand: escapeshellarg(getPhpCliBinary()) . ' tests.php {junit}',
	);
	// the run goes on after the raise in a process that loads what it installed, the tests and the check at the end too
	$runner = new PlanRunner(createSteppedRunProject($root), composer: new PlanRunnerComposer($root));
	$raised = $runner->run($plan, approve: fn() => true);
	Assert::same([RunStop::Raised, 1], [$raised->stop, $raised->step]);
	$outcome = $runner->run($plan, resumed: $raised);
	Assert::same([RunStop::Tests, 1, ['Raise::raise']], [$outcome->stop, $outcome->step, $outcome->newFailures]);
	Assert::same('xx', file_get_contents("$root/runs.txt")); // before the first step and after the raise
	// a run started anew, as one that could not go on in a process of its own is, finds the raise untested in the journal
	$again = $runner->run($plan);
	Assert::same([RunStop::Tests, 1, ['Raise::raise']], [$again->stop, $again->step, $again->newFailures]);
	Assert::same('xxx', file_get_contents("$root/runs.txt"));
	Assert::same(['composer.json'], $outcome->raisedFiles);
	Assert::match(
		"%A%Raised     composer.json changed, which `dresscode upgrade abort` puts back as they were before the raise and installs\n"
		. 'Failed     step 1 raised the dependencies, which stay raised, and these tests now fail: Raise::raise%A%',
		new DressCode\Console\RunOutcomePrinter($plan, $outcome)->print(new Nette\CommandLine\Console),
	);

	// the journal keeps the files until a step after the raise is done, or the user puts them back
	$state = new RunState(createSteppedRunProject($root));
	Assert::same(1, $state->journal->findStep());
	$restoration = $state->restore(createSteppedRunProject($root), new PlanRunnerComposer($root));
	Assert::same([['composer.json'], [], false], [$restoration->restored, $restoration->left, $restoration->installs]);
	Assert::same('{"require": {"php": "^8.0"}}', file_get_contents("$root/composer.json"));
	Assert::null($state->journal->findStep());
});


test('an install of what abort put back that fails is tried again by the next abort, which forgets the journal only then', function () {
	$root = createSteppedProject('steps-abort-install');
	file_put_contents("$root/composer.lock", '{}');
	$project = createSteppedRunProject($root);
	$state = new RunState($project);
	$state->journal->recordBefore(2, $root);
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4"}}');
	$state->journal->recordAfter($root);
	$failures = 1;
	$composer = new PlanRunnerComposer($root, onInstall: function () use (&$failures): void {
		if ($failures-- > 0) {
			throw new ComposerException('Composer failed: the network is down');
		}
	});

	$first = $state->restore($project, $composer);
	Assert::same(
		[['composer.json'], true, 'Composer failed: the network is down'],
		[$first->restored, $first->installs, $first->installFailure],
	);
	Assert::same([2, true], [$state->journal->findStep(), $state->journal->isAwaitingInstall()]);

	$dryRun = $state->restore($project, $composer, dryRun: true);
	Assert::same([[], true], [$dryRun->restored, $dryRun->installs]);

	$second = $state->restore($project, $composer);
	Assert::same([[], true, null], [$second->restored, $second->installs, $second->installFailure]);
	Assert::null($state->journal->findStep());
});


test('the journal of Composer puts back what the raise changed, a file it created removed, a file changed since left', function () {
	$root = createTempDir('composer-journal');
	$journal = new DressCode\Upgrade\ComposerJournal("$root/state");
	file_put_contents("$root/composer.json", 'before');
	$journal->recordBefore(2, $root);
	file_put_contents("$root/composer.json", 'raised');
	file_put_contents("$root/composer.lock", 'lock');
	$journal->recordAfter($root);
	$journal->recordBefore(3, $root); // a later raise keeps what was before the first one
	Assert::same([2, ['composer.json', 'composer.lock']], [$journal->findStep(), $journal->findChanged()]);

	file_put_contents("$root/composer.lock", 'edited');
	Assert::same(
		[['composer.json'], ['composer.lock' => 'The file changed after the raise of step 2, so it is not written back.']],
		$journal->restore($root),
	);
	Assert::same('before', file_get_contents("$root/composer.json"));
	Assert::same('edited', file_get_contents("$root/composer.lock"));
	// a file left stays kept, to go back once the change of it is undone, and one written back is no change any more
	Assert::same([2, ['composer.lock'], true], [$journal->findStep(), $journal->findChanged(), $journal->isAwaitingInstall()]);
	file_put_contents("$root/composer.lock", 'lock');
	Assert::same([['composer.lock'], []], $journal->restore($root));
	Assert::same([[], false], [$journal->findChanged(), $journal->isAwaitingInstall()]);
	$journal->forget();

	file_put_contents("$root/composer.lock", 'edited');
	$journal->recordBefore(2, $root);
	file_put_contents("$root/composer.lock", 'new');
	$journal->recordAfter($root);
	Assert::same([['composer.lock'], []], $journal->restore($root));
	Assert::same('edited', file_get_contents("$root/composer.lock"));
	$journal->forget();

	unlink("$root/composer.lock");
	$journal->recordBefore(2, $root);
	file_put_contents("$root/composer.lock", 'created');
	$journal->recordAfter($root);
	Assert::same([['composer.lock'], []], $journal->restore($root));
	Assert::false(is_file("$root/composer.lock"));
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
	// the tasks of the plan say so too, as nothing of the file is checked
	Assert::match(
		'Syntax error: %a%',
		new PlanRunner(createSteppedRunProject($root))->findTasks(createSteppedPlan())[1]['src/Broken.php'] ?? '',
	);

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


test('with commit, each topic is committed with the files it changed once the tests of its step pass', function () {
	$root = createSteppedProject('steps-commit');
	foreach (['GIT_AUTHOR_NAME', 'GIT_COMMITTER_NAME'] as $name) {
		putenv("$name=Test");
	}

	foreach (['GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_EMAIL'] as $name) {
		putenv("$name=test@example.com");
	}

	$git = fn(string ...$args) => ProcessRunner::run(['git', ...array_values($args)], $root, 60);
	$git('init', '--quiet');
	$git('commit', '--quiet', '--allow-empty', '-m', 'start');

	$runner = new PlanRunner(createSteppedRunProject($root), commit: true);

	// a change of the working tree would go into the commit of a topic changing that file
	$git('add', 'src/Code.php');
	$git('commit', '--quiet', '-m', 'code');
	file_put_contents("$root/src/Code.php", "\n", FILE_APPEND);
	Assert::exception(
		fn() => $runner->run(createSteppedPlan()),
		DressCode\ConfigurationException::class,
		'A commit of a topic takes the files it changed whole, and the working tree has changes of its own; %a%',
	);
	$git('checkout', '--', 'src/Code.php');
	// the plan the run saves is no change of the code
	file_put_contents("$root/dresscode-upgrade.neon", "# plan\n");
	$git('add', 'dresscode-upgrade.neon');
	$git('commit', '--quiet', '-m', 'plan');
	file_put_contents("$root/dresscode-upgrade.neon", "# plan saved anew\n");

	$runner->run(createSteppedPlan());
	[, $log] = $git('log', '-1', '--name-only');
	Assert::match(
		"%A%    upgrade: upgrading.php.implicitNullable\n\n    DressCode-Upgrade-Step: 1\n\nsrc/Code.php\n",
		str_replace("\r", '', $log),
	);

	// a raise the run makes is committed too, so that the next run finds no changes of its own
	$runner = new PlanRunner(createSteppedRunProject($root), commit: true, composer: new PlanRunnerComposer($root));
	$raised = $runner->run(createSteppedPlan(), approve: fn() => true);
	Assert::same(RunStop::Done, $runner->run(createSteppedPlan(), resumed: $raised)->stop);
	Assert::contains('upgrade: raise php to ^8.4', $git('log', '-1')[1]);
	Assert::false($runner->hasOwnChanges());
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}}');
	$git('commit', '--quiet', '-am', 'php ^8.0');

	// a commit git refuses ends the run, what the step changed left in the working tree
	file_put_contents("$root/src/Code.php", "<?php\r\n\r\nfunction run(int \$count = null): void\r\n{\r\n}\r\n");
	$git('commit', '--quiet', '-am', 'back');
	file_put_contents("$root/.git/hooks/pre-commit", "#!/bin/sh\necho refused by the hook\nexit 1\n");
	chmod("$root/.git/hooks/pre-commit", 0o755);
	$outcome = $runner->run(createSteppedPlan());
	Assert::same(
		[RunStop::Commit, 1, '`git commit` failed: refused by the hook; what step 1 changed is left uncommitted in the working tree.'],
		[$outcome->stop, $outcome->step, $outcome->commitFailure],
	);
	Assert::contains('?int', (string) file_get_contents("$root/src/Code.php"));
});


test('with commit, a project git finds no repository of is refused before anything changes', function () {
	$root = createSteppedProject('steps-commit-no-git');
	$code = file_get_contents("$root/src/Code.php");
	Assert::exception(
		fn() => new PlanRunner(createSteppedRunProject($root), commit: true)->run(createSteppedPlan()),
		DressCode\ConfigurationException::class,
		'`--commit` commits into the git repository of the project, and git finds none there that tracks it, or refuses to tell its status.',
	);
	Assert::same($code, file_get_contents("$root/src/Code.php"));
});


test('with commit, a file the tests change after its step wrote it is kept as it is, and the step stays uncommitted', function () {
	$root = createSteppedProject('steps-commit-edited');
	file_put_contents("$root/edit.php", <<<'XX'
		<?php
		if (str_contains(file_get_contents(__DIR__ . '/src/Code.php'), '?int')) {
			file_put_contents(__DIR__ . '/src/Code.php', "// edited\n", FILE_APPEND);
		}
		file_put_contents($argv[1], '<testsuites><testsuite><testcase classname="Code" name="run"/></testsuite></testsuites>');
		XX);
	$git = fn(string ...$args) => ProcessRunner::run(['git', ...array_values($args)], $root, 60);
	$git('init', '--quiet');
	$git('add', '--all');
	$git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'start');

	$outcome = new PlanRunner(createSteppedRunProject($root), commit: true)->run(
		createSteppedPlan(escapeshellarg(getPhpCliBinary()) . ' edit.php {junit}'),
	);
	Assert::same(
		[
			RunStop::Commit,
			'`src/Code.php` changed after step 1 wrote it, so the step is left uncommitted in the working tree; commit it yourself.',
		],
		[$outcome->stop, $outcome->commitFailure],
	);
	$code = (string) file_get_contents("$root/src/Code.php");
	Assert::contains('?int', $code);
	Assert::contains("// edited\n", $code);
	Assert::match("%A%    start\n", str_replace("\r", '', $git('log', '-1')[1]));
});


test('with commit, a step leaving a file it cannot process is committed whole once a later run processes it', function () {
	$root = createSteppedProject('steps-commit-errors');
	file_put_contents("$root/src/Broken.php", "<?php function {\n");
	$git = fn(string ...$args) => ProcessRunner::run(['git', ...array_values($args)], $root, 60);
	$git('init', '--quiet');
	$git('add', '--all');
	$git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'start');
	$runner = new PlanRunner(createSteppedRunProject($root), commit: true);

	Assert::same(RunStop::Unprocessed, $runner->run(createSteppedPlan())->stop);
	Assert::match("%A%    start\n", str_replace("\r", '', $git('log', '-1')[1]));
	Assert::contains('?int', (string) file_get_contents("$root/src/Code.php"));

	// what the run keeps lies in the repository, and a plan whose step 1 is another one now refuses to commit it
	Assert::true(is_dir("$root/.git/dresscode-upgrade"));
	$edited = new Plan([Target::php('8.4')], [new FixStep('edited', ['upgrading.php.deprecatedApi'])], '1.0');
	Assert::exception(
		fn() => $runner->run($edited),
		DressCode\ConfigurationException::class,
		'The plan changed since a run left step 1 uncommitted, waiting for files it could not process; %a%',
	);

	// a file the step wrote and someone changed since is a change of their own, which the commit would take
	$written = (string) file_get_contents("$root/src/Code.php");
	file_put_contents("$root/src/Code.php", "$written// mine\n");
	Assert::exception(
		fn() => $runner->run(createSteppedPlan()),
		DressCode\ConfigurationException::class,
		'A commit of a topic takes the files it changed whole, and the working tree has changes of its own; %a%',
	);
	file_put_contents("$root/src/Code.php", $written);

	// the user makes the file parse, which is no change of their own for the run, and the step goes into one commit
	file_put_contents("$root/src/Broken.php", "<?php\n\nfunction broken(int \$count = null): void\n{\n}\n");
	Assert::same(RunStop::Composer, $runner->run(createSteppedPlan())->stop);
	Assert::match(
		"%A%    upgrade: upgrading.php.implicitNullable\n%A%\nsrc/Broken.php\nsrc/Code.php\n",
		str_replace("\r", '', $git('log', '-1', '--name-only')[1]),
	);
});


test('a fix resolves the names with what the namespaces declare, as the estimate does', function () {
	$root = createSteppedProject('steps-names');
	file_put_contents("$root/src/Close.php", "<?php\n\nnamespace App;\n\nfunction close(\$handle): void\n{\n\tcurl_close(\$handle);\n}\n");
	$plan = new Plan([Target::php('8.4')], [new FixStep('bridge', ['php'])], '1.0');
	$runner = new PlanRunner(createSteppedRunProject($root));

	Assert::same(['src/Close.php'], $runner->run($plan)->applied[1]['php']);
	Assert::notContains('curl_close', (string) file_get_contents("$root/src/Close.php"));
});


test('a plan leaving a task is not done, and a step it does not run is refused', function () {
	$root = createSteppedProject('steps-left');
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.1"}}');
	file_put_contents("$root/src/Date.php", "<?php\n\nfunction month(): string\n{\n\treturn strftime('%B');\n}\n");
	$plan = new Plan(
		[Target::php('8.4')],
		[new FixStep('bridge', ['php']), new FixStep('later', ['php'], skip: true)],
		'1.0',
	);
	$runner = new PlanRunner(createSteppedRunProject($root));

	$outcome = $runner->run($plan);
	Assert::same([RunStop::Tasks, 1], [$outcome->stop, $outcome->step]);
	Assert::same(['src/Date.php'], array_map(fn(Task $task) => $task->path, $outcome->tasks['php']));

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


test(
	'a raise approved lets the run go on once Composer made it, one it did not make or that is not approved leaves the run waiting',
	function () {
		$root = createSteppedProject('steps-raised');
		$project = createSteppedRunProject($root);
		$asked = [];
		$approve = function (DependencyStep $step, array $changes) use (&$asked): bool {
			$asked[] = $step->title;
			return true;
		};
		$runner = new PlanRunner($project, composer: new PlanRunnerComposer($root, onRequire: fn() => null));
		Assert::same(2, $runner->run(createSteppedPlan(), approve: $approve)->step);
		Assert::same(['raise php to ^8.4'], $asked);
		Assert::same(
			[RunStop::Composer, 2],
			[($outcome = $runner->run(createSteppedPlan(), approve: fn() => false))->stop, $outcome->step],
		);

		// where Composer fails, the outcome says why, beside what the run tells
		$fail = fn() => throw new DressCode\Upgrade\ComposerException('Composer failed: Your requirements could not be resolved');
		$runner = new PlanRunner($project, composer: new PlanRunnerComposer($root, onRequire: $fail));
		$outcome = $runner->run(createSteppedPlan(), approve: fn() => true)->withWarnings(['The types are not there.']);
		Assert::same(
			[RunStop::Composer, 'Composer failed: Your requirements could not be resolved', ['The types are not there.']],
			[$outcome->stop, $outcome->composerFailure, $outcome->warnings],
		);
		Assert::match(
			"%A%Warning    The types are not there.\nFailed     step 2, Composer failed: Your requirements could not be resolved; raise php to ^8.4: run %A%",
			new DressCode\Console\RunOutcomePrinter(createSteppedPlan(), $outcome)->print(new Nette\CommandLine\Console),
		);

		$runner = new PlanRunner($project, composer: new PlanRunnerComposer($root));
		$raised = $runner->run(createSteppedPlan(), approve: fn() => true);
		Assert::same([RunStop::Raised, 2], [$raised->stop, $raised->step]);
		Assert::equal(
			new RunOutcome(RunStop::Done, null, [1 => ['upgrading.php.implicitNullable' => []]]),
			$runner->run(createSteppedPlan(), resumed: $raised),
		);
	},
);


test('the files of Composer a raise changes are kept to go back where they lie above the root of the configuration', function () {
	$above = createTempDir('steps-composer-above');
	mkdir("$above/app");
	$root = "$above/app";
	foreach (['src', 'dresscode.neon', 'tests.php'] as $entry) {
		rename(createSteppedProject('steps-composer-below') . "/$entry", "$root/$entry");
	}

	file_put_contents("$above/composer.json", '{"require": {"php": "^8.0"}}');
	$runner = new PlanRunner(createSteppedRunProject($root), composer: new PlanRunnerComposer($above));
	$plan = new Plan([Target::php('8.4')], [new ComposerStep('raise php to ^8.4', ['php' => '^8.4'])], '1.0');
	$raised = $runner->run($plan, approve: fn() => true);
	Assert::same(RunStop::Remaining, $runner->run($plan, resumed: $raised)->stop); // the bridge is not in the plan
	Assert::same(['composer.json'], $runner->state->journal->findChanged());
	Assert::same([['composer.json'], []], $runner->state->journal->restore(createSteppedRunProject($root)->findComposerRoot()));
	Assert::same('{"require": {"php": "^8.0"}}', file_get_contents("$above/composer.json"));
});


test('the update to the newest versions is done once Composer says it would change nothing, and asked for otherwise', function () {
	$root = createSteppedProject('steps-newest');
	file_put_contents("$root/src/Code.php", "<?php\n\nfunction run(?int \$count = null): void\n{\n}\n");
	$step = new UpdateStep('update to the newest versions');
	$plan = new Plan([Target::php('8.4')], [$step], '1.0');
	$composer = new PlanRunnerComposer($root, updateChanges: ['- Upgrading acme/mail (v3.0.0 => v3.2.1)']);
	$runner = new PlanRunner(createSteppedRunProject($root), composer: $composer);
	$asked = [];
	$approve = function (DependencyStep $step, array $changes) use (&$asked): bool {
		$asked[] = $changes;
		return true;
	};
	$raised = $runner->run($plan, approve: $approve);
	Assert::same([RunStop::Raised, RunStop::Done], [$raised->stop, $runner->run($plan, resumed: $raised)->stop]);
	Assert::same(RunStop::Done, $runner->run($plan, approve: $approve)->stop);
	Assert::same([['- Upgrading acme/mail (v3.0.0 => v3.2.1)']], $asked); // done, so not asked again
	Assert::same(1, $composer->updates);
	Assert::same(['composer update'], $step->formatCommands());
});


test('Composer and the tests are boundaries a test replaces, to fail exactly where it wants', function () {
	$root = createSteppedProject('steps-boundaries');
	$composer = new class implements PackageManager {
		public function findBlockers(Target $target): Blockers
		{
			return new Blockers($target, unknown: 'Composer did not answer within 60 s');
		}


		public function previewRequire(array $constraints, bool $update = true, ?string $platform = null): array
		{
			return [];
		}


		public function require(array $constraints, bool $update = true, ?string $platform = null): void
		{
		}


		public function previewUpdate(): array
		{
			throw new ComposerException('Composer failed: the network is down');
		}


		public function update(): void
		{
		}


		public function install(): void
		{
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
	$outcome = $runner->run(new Plan([$target], [new UpdateStep('update to the newest versions')], '1.0'));
	Assert::same([RunStop::Composer, 'Composer failed: the network is down'], [$outcome->stop, $outcome->composerFailure]);

	Assert::same(
		'the tests of `tests {junit}` do not run before the first step, as the command of the tests ended with exit code 255',
		new PlanRunner($project, tests: $tests)->run(createSteppedPlan('tests {junit}'))->crash,
	);
});


test('a raise a fix follows stops the run, which goes on after it from the outcome handed over as data', function () {
	$root = createSteppedProject('steps-resumed');
	$plan = new Plan([Target::php('8.4')], [
		new FixStep('works on the versions allowed today', ['upgrading.php.implicitNullable']),
		new ComposerStep('raise php to ^8.4', ['php' => '^8.4']),
		new FixStep('needs the targets', ['upgrading.php.implicitNullable']),
	], '1.0');
	$runner = new PlanRunner(createSteppedRunProject($root), composer: new PlanRunnerComposer($root));
	$outcome = $runner->run($plan, approve: fn() => true);
	Assert::equal(new RunOutcome(RunStop::Raised, 2, [1 => ['upgrading.php.implicitNullable' => ['src/Code.php']]]), $outcome);

	$handed = RunOutcome::fromArray(json_decode(json_encode($outcome->toArray(), JSON_THROW_ON_ERROR), associative: true));
	Assert::equal($outcome, $handed);
	Assert::equal(
		new RunOutcome(RunStop::Done, null, [
			1 => ['upgrading.php.implicitNullable' => ['src/Code.php']],
			3 => ['upgrading.php.implicitNullable' => []],
		]),
		$runner->run($plan, resumed: $handed),
	);

	// what a fix left goes over as data too
	$violation = new DressCode\Violation(
		'upgrading.php.deprecatedApi',
		'Deprecated.',
		3,
		5,
		DressCode\Severity::Error,
		'abc',
		fixable: false,
	);
	$outcome = new RunOutcome(RunStop::Raised, 2, tasks: ['php' => [new Task('src/Code.php', $violation)]]);
	Assert::equal($outcome, RunOutcome::fromArray(json_decode(json_encode($outcome->toArray(), JSON_THROW_ON_ERROR), associative: true)));
});


test(
	'the tasks are what the fixes of the plan leave in the code as it stands, changing nothing',
	function () {
		$root = createSteppedProject('steps-tasks');
		file_put_contents("$root/composer.json", '{"require": {"php": "^8.1"}}');
		$code = <<<'XX'
			<?php

			function month(): string
			{
				return strftime('%B');
			}

			function day(): string
			{
				// dresscode:ignore upgrading.php.deprecatedApi
				return strftime('%d');
			}

			function year(): string
			{
				// dresscode:ignore upgrading.php.deprecatedApi -- the report needs the locale of the server
				return strftime('%Y');
			}

			XX;
		file_put_contents("$root/src/Date.php", $code);
		$plan = new Plan([Target::php('8.4')], [new FixStep('bridge', ['php', 'upgrading.php.implicitNullable'])], '1.0');
		$runner = new PlanRunner(createSteppedRunProject($root));

		[$tasks, $unprocessed] = $runner->findTasks($plan);
		Assert::same([], $unprocessed);
		Assert::same([['src/Date.php', 5]], array_map(fn(Task $task) => [$task->path, $task->violation->line], $tasks['php']));
		Assert::equal(
			[new Postponement('src/Date.php', 10, 'dresscode:ignore upgrading.php.deprecatedApi')],
			Postponement::findIn($runner->project, $plan->paths),
		);
		// a suppression without a reason only puts a task off; and nothing is changed, the fix of `Code.php` included
		Assert::same($code, file_get_contents("$root/src/Date.php"));
		Assert::notContains('?int', (string) file_get_contents("$root/src/Code.php"));

		// so a run whose fixes leave nothing else is not done either
		file_put_contents(
			"$root/src/Date.php",
			str_replace(
				"\treturn strftime('%B');",
				"\t// dresscode:ignore upgrading.php.deprecatedApi -- legacy\n\treturn strftime('%B');",
				$code,
			),
		);
		$outcome = $runner->run($plan);
		Assert::same([RunStop::Tasks, null], [$outcome->stop, $outcome->step]);
		Assert::equal([new Postponement('src/Date.php', 11, 'dresscode:ignore upgrading.php.deprecatedApi')], $outcome->postponed);
	},
);


test('the tasks hold what the check of the whole scope at the end of a run finds and no topic of the plan does', function () {
	$root = createSteppedProject('steps-tasks-remaining');
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.1"}}');
	file_put_contents("$root/src/Date.php", "<?php\n\nfunction month(): string\n{\n\treturn strftime('%B');\n}\n");
	$plan = new Plan([Target::php('8.4')], [new FixStep('bridge', ['upgrading.php.implicitNullable'])], '1.0');

	[$tasks] = new PlanRunner(createSteppedRunProject($root))->findTasks($plan);
	Assert::same(['deprecations'], array_keys($tasks));
	Assert::same(
		[['src/Date.php', 5, 'upgrading.php.deprecatedApi']],
		array_map(fn(Task $task) => [$task->path, $task->violation->line, $task->violation->decision], $tasks['deprecations']),
	);
});
