<?php declare(strict_types=1);

use DressCode\Console\PlanPrinter;
use DressCode\Upgrade\Plan;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the plan says which command tests after which step', function () {
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$tests = fn(?string $test, ?string $quick) => explode('Tests      ', new PlanPrinter(
		new Plan([], [], '1.0', testCommand: $test, quickTestCommand: $quick),
		'an application',
	)->print($console))[1];

	Assert::same("not run, no `--test-command` given\n", $tests(null, null));
	Assert::same("`vendor/bin/tester` after every step\n", $tests('vendor/bin/tester', null));
	Assert::same("`vendor/bin/tester unit` after every step\n", $tests(null, 'vendor/bin/tester unit'));
	Assert::same(
		"`vendor/bin/tester unit` after every step, `vendor/bin/tester` after a raise and the last one\n",
		$tests('vendor/bin/tester', 'vendor/bin/tester unit'),
	);
});
