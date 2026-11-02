<?php declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/Application.helpers.php';


$root = createConsoleProject();


test('workers give the same results as the in-process run', function () use ($root) {
	file_put_contents(
		"$root/jobs.php",
		"<?php\nreturn new DressCode\\Config(paths: ['jobs'], decisions: ['file' => ['trailingWhitespace' => 'forbidden']]);\n",
	);
	@mkdir("$root/jobs"); // @ directory may already exist
	file_put_contents("$root/jobs/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/jobs/b.php", "<?php\n\$x;\n");
	file_put_contents("$root/jobs/c.php", "<?php\n\$a; \n\$a;\t\n");
	$config = ['--config', "$root/jobs.php", '--no-cache'];
	[, $expected] = runApp($root, ['check', ...$config, '--jobs', '1']);
	[$code, $out, $err] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::same($expected, $out);
	Assert::match("%A%a.php\n%A%c.php\n%A%FOUND  3 violations, a fix leaves none in 2 of 3 files\n", $out);

	[$code, $out] = runApp($root, ['fix', ...$config, '--jobs', '2']);
	Assert::same(0, $code);
	Assert::match("%A%a.php  rewritten\n%A%c.php  rewritten\n\nFIXED  3 violations found, none remaining in 2 of 3 files\n", $out);
	Assert::same("<?php\n\$a;\n\$a;\n", file_get_contents("$root/jobs/c.php"));
	[$code, $out] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same(0, $code);
	Assert::match("%A%OK  3 files, all up to the dress code\n", $out);
});


test('a worker builds a rule of a plugin by the factory of its manifest, as the process alone does', function () use ($root) {
	$plugin = var_export(__DIR__ . '/fixtures/factory-plugin.php', return: true);
	file_put_contents(
		"$root/factory.php",
		"<?php\nrequire_once $plugin;\nreturn new DressCode\\Config(use: [WorkerFactoryPlugin::class], paths: ['jobs'], decisions: ['workerFactory' => ['variables' => 'forbidden']]);\n",
	);
	$config = ['--config', "$root/factory.php", '--no-cache'];
	[, $expected] = runApp($root, ['check', ...$config, '--jobs', '1']);
	[$code, $out, $err] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::same($expected, $out);
	Assert::contains('A variable, built by the factory.', $out);
});


test('a file a worker cannot write fails alone, the others are fixed', function () use ($root) {
	file_put_contents(
		"$root/readonly.php",
		"<?php\nreturn new DressCode\\Config(paths: ['readonly'], decisions: ['file' => ['trailingWhitespace' => 'forbidden']]);\n",
	);
	@mkdir("$root/readonly"); // @ directory may already exist
	file_put_contents("$root/readonly/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/readonly/b.php", "<?php\n\$b; \n");
	chmod("$root/readonly/a.php", 0o444);
	try {
		[$code, $out, $err] = runApp($root, ['fix', '--config', "$root/readonly.php", '--no-cache', '--jobs', '2']);
	} finally {
		chmod("$root/readonly/a.php", 0o666);
	}

	Assert::same('', $err);
	Assert::same(2, $code);
	Assert::match('%A%Cannot write file `readonly/a.php`.%A%', $out);
	Assert::same("<?php\n\$a; \n", file_get_contents("$root/readonly/a.php"));
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/readonly/b.php"));
});


test('rules that do not fit are a configuration error with workers too', function () use ($root) {
	file_put_contents("$root/typo.php", <<<'PHP'
		<?php
		#[DressCode\RuleInfo(DressCode\Stage::Structure)]
		final class ConsoleTypo extends DressCode\NodeRule
		{
			public static function getDecisions(): array
			{
				return [new DressCode\Decision('project.typo', DressCode\Domain::state('forbidden'), 'typo')];
			}

			public function getVisitedNodes(): array
			{
				return ['Acme\Shop\NoSuchNode'];
			}
		}

		return new DressCode\Config(rules: [ConsoleTypo::class], decisions: ['project' => ['typo' => 'forbidden']], paths: ['src']);
		PHP);
	[$code, , $err] = runApp($root, ['check', '--config', "$root/typo.php", '--no-cache', '--jobs', '2']);
	Assert::same(3, $code);
	Assert::match('%A%Rule `ConsoleTypo` visits `Acme\Shop\NoSuchNode`, which is no class of a node or a token.%A%', $err);
});


test('a worker has the settings of the process the ini file alone would not give it', function () use ($root) {
	// the configuration turns the rule on by a setting, which a worker has only when it is handed over
	file_put_contents(
		"$root/ini.php",
		"<?php\nreturn new DressCode\\Config(paths: ['ini'], decisions: ['file' => ['trailingWhitespace' => ini_get('user_agent') === 'dresscode-test' ? 'forbidden' : 'keep']]);\n",
	);
	@mkdir("$root/ini"); // @ directory may already exist
	file_put_contents("$root/ini/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/ini/b.php", "<?php\n\$b; \n");
	$previous = ini_set('user_agent', 'dresscode-test');
	try {
		[$code, $out, $err] = runApp($root, ['check', '--config', "$root/ini.php", '--no-cache', '--jobs', '2']);
	} finally {
		ini_set('user_agent', (string) $previous);
	}

	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  2 violations%A%', $out);
});


test('a baseline generated by workers is the one the single process writes, and it does not stand in the way of a fix', function () {
	$root = createConsoleProject();
	$a = "<?php\nbase64_decode(\$a);\n";
	$c = "<?php\nbase64_decode(\$a);\nbase64_decode(\$a);\n";
	mkdir("$root/jobs");
	file_put_contents("$root/jobs/a.php", $a);
	file_put_contents("$root/jobs/b.php", "<?php\n\$x;\n");
	file_put_contents("$root/jobs/c.php", $c);
	file_put_contents(
		"$root/jobs.php",
		"<?php\nreturn new DressCode\\Config(paths: ['jobs'], baseline: 'jobs-baseline.neon', decisions: ['mistakes' => ['strictArgument' => 'required']]);\n",
	);
	$config = ['--config', "$root/jobs.php", '--no-cache'];

	// the risky fixes the configuration does not accept are what a fix leaves
	runApp($root, ['baseline', ...$config, '--jobs', '1']);
	$single = (string) file_get_contents("$root/jobs-baseline.neon");
	runApp($root, ['baseline', ...$config, '--jobs', '3']);
	Assert::same($single, (string) file_get_contents("$root/jobs-baseline.neon"));
	Assert::match('%A%decision: mistakes.strictArgument%A%', $single);

	// what the baseline holds is not reported, and the fix the run does not allow is not made
	[$code, $out] = runApp($root, ['fix', ...$config, '--jobs', '3']);
	Assert::same(0, $code);
	Assert::match("%A%OK  3 violations in the baseline in 3 files\n", $out);
	Assert::same($a, (string) file_get_contents("$root/jobs/a.php"));

	// once the run allows it, the fix is made, with workers as without them
	foreach (['1', '3'] as $jobs) {
		file_put_contents("$root/jobs/a.php", $a);
		file_put_contents("$root/jobs/c.php", $c);
		[$code] = runApp($root, ['fix', ...$config, '--fix-risky', '--jobs', $jobs]);
		Assert::same(0, $code);
		Assert::same("<?php\nbase64_decode(\$a, true);\n", (string) file_get_contents("$root/jobs/a.php"));
	}
});
