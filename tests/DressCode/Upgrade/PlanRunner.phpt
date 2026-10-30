<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Upgrade\{Plan, PlanRunner, ProcessRunner, Project, RunOutcome, RunStop, Step, StepKind, Target, Task, TestResults};
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


function createSteppedPlan(?string $testCommand = null): Plan
{
	return new Plan(
		[Target::php('8.4')],
		[
			new Step(StepKind::Fix, 'works on the versions allowed today', ['upgrading.php.implicitNullable']),
			new Step(StepKind::Composer, 'raise php to ^8.4', require: ['php' => '^8.4']),
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
		XX, 'W:/acme');

	Assert::same([
		'App.Tests.MailTest::testSend with data set #0' => true,
		'App/Tests/MailTest::testQueue' => false,
		'tests/Mail.phpt::W:/acme/tests/Mail.phpt' => false,
	], $results->tests);
	// what failed before, or passes now, is no new failure
	Assert::same(
		['tests/Mail.phpt::W:/acme/tests/Mail.phpt'],
		$results->findNewFailures(new TestResults(['App/Tests/MailTest::testQueue' => false])),
	);
	Assert::same([], TestResults::fromJunit('no xml', 'W:/acme')->tests);
});


test('a fix step runs over the project as it is and stops before the raise of Composer, which it waits for', function () {
	$root = createSteppedProject('steps-blocked');
	$runner = new PlanRunner(createSteppedRunProject($root));

	Assert::equal(
		new RunOutcome(RunStop::Composer, 2, [1 => ['upgrading.php.implicitNullable' => ['src/Code.php']]]),
		$runner->run(createSteppedPlan()),
	);
	Assert::same("<?php\r\n\r\nfunction run(?int \$count = null): void\r\n{\r\n}\r\n", file_get_contents("$root/src/Code.php"));

	// raised, the run goes on from the code as it is, the fix left with nothing to do
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4"}}');
	Assert::equal(new RunOutcome(RunStop::Done, null, [1 => ['upgrading.php.implicitNullable' => []]]), $runner->run(createSteppedPlan()));
});


test('a step whose tests newly fail goes back byte for byte, and a command writing no results is refused', function () {
	$root = createSteppedProject('steps-failed');
	$code = file_get_contents("$root/src/Code.php");
	$runner = new PlanRunner(createSteppedRunProject($root));
	$php = escapeshellarg(PHP_BINARY);

	$outcome = $runner->run(createSteppedPlan("$php tests.php {junit}"));
	// a test of Nette Tester is named by its file within the root
	Assert::same([RunStop::Tests, 1, ['tests/Code.phpt::run']], [$outcome->stop, $outcome->step, $outcome->failures]);
	Assert::same($code, file_get_contents("$root/src/Code.php"));

	Assert::exception(
		fn() => new PlanRunner(new Project(new Config(paths: ['src'], cacheDir: 'other'), $root, "$root/dresscode.neon"))->run(
			createSteppedPlan("$php -r 1;"),
		),
		DressCode\ConfigurationException::class,
		'The command testing the project, `%a%`, wrote no JUnit file where `{junit}` stands.',
	);
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
});


test('a fix resolves the names with what the namespaces declare, as the estimate does', function () {
	$root = createSteppedProject('steps-names');
	file_put_contents("$root/src/Close.php", "<?php\n\nnamespace App;\n\nfunction close(\$handle): void\n{\n\tcurl_close(\$handle);\n}\n");
	$plan = new Plan([Target::php('8.4')], [new Step(StepKind::Fix, 'bridge', ['php'])], '1.0');
	$runner = new PlanRunner(createSteppedRunProject($root));

	Assert::same(['src/Close.php'], $runner->run($plan)->fixed[1]['php']);
	Assert::notContains('curl_close', (string) file_get_contents("$root/src/Close.php"));
});


test('a raise the caller makes lets the run go on, one it only claims leaves the run waiting', function () {
	$root = createSteppedProject('steps-raised');
	$runner = new PlanRunner(createSteppedRunProject($root));
	$asked = [];
	$claim = function (Step $step) use (&$asked): bool {
		$asked[] = $step->title;
		return true;
	};
	Assert::same(2, $runner->run(createSteppedPlan(), raise: $claim)->step);
	Assert::same(['raise php to ^8.4'], $asked);

	$raise = function () use ($root): bool {
		file_put_contents("$root/composer.json", '{"require": {"php": "~8.4.0"}}');
		return true;
	};
	Assert::equal(
		new RunOutcome(RunStop::Done, null, [1 => ['upgrading.php.implicitNullable' => []]]),
		$runner->run(createSteppedPlan(), raise: $raise),
	);
});


test(
	'the tasks are what the fixes of the plan leave in the code as it stands, changing nothing',
	function () {
		$root = createSteppedProject('steps-tasks');
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
		$plan = new Plan([Target::php('8.4')], [new Step(StepKind::Fix, 'bridge', ['php', 'upgrading.php.implicitNullable'])], '1.0');
		$runner = new PlanRunner(createSteppedRunProject($root));

		$tasks = $runner->findTasks($plan);
		Assert::same([['src/Date.php', 5]], array_map(fn(Task $task) => [$task->path, $task->violation->line], $tasks['php']));
		Assert::same(
			[['path' => 'src/Date.php', 'line' => 10, 'comment' => 'dresscode:ignore upgrading.php.deprecatedApi']],
			$runner->findPostponed(),
		);
		// a suppression without a reason only puts a task off; and nothing is changed, the fix of `Code.php` included
		Assert::same($code, file_get_contents("$root/src/Date.php"));
		Assert::notContains('?int', (string) file_get_contents("$root/src/Code.php"));
	},
);
