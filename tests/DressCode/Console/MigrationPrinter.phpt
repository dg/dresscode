<?php declare(strict_types=1);

use DressCode\Console\MigrationPrinter;
use DressCode\Interop\Translation;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function migrationConsole(ColorDepth $depth = ColorDepth::None, bool $terminal = false): Console
{
	return new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, $depth, terminal: $terminal);
}


test('the rules left to the project are named up to five, in the order of their names', function () {
	$translation = new Translation(ruleCount: 6);
	foreach (['f', 'e', 'd', 'c', 'b', 'a'] as $rule) {
		$translation->translateRule($rule, function () use ($translation, $rule): void {
			$translation->request("`$rule`: set it by hand.");
		});
	}

	Assert::match(
		"6 rules in your `a.php`:\n"
		. "6  you need to finish by hand: `a`, `b`, `c`, `d`, `e` and 1 more\n"
		. "1 thing to settle by hand about the configuration as a whole.\n"
		. "\n"
		. "The details are at the end of `x.neon`.\n"
		. "Next, settle what is left by hand; then `dresscode check` shows what the configuration finds in your code.\n"
		. "\n"
		. "WRITTEN  x.neon\n",
		new MigrationPrinter($translation, 'a.php', 'x.neon', '/p/x.neon', 0)->print(migrationConsole()),
	);
});


test('the next step names the rules to finish first', function () {
	$translation = new Translation(ruleCount: 1);
	$translation->addPreset('dresscode/psr12');
	$translation->translateRule('a', function () use ($translation): void {
		$translation->request('`a`: set it by hand.');
	});
	Assert::contains(
		'Next, finish that rule; then `dresscode check`',
		new MigrationPrinter($translation, 'a.php', 'x.neon', '/p/x.neon', 0)->print(migrationConsole()),
	);
});


test('the written file is a link only in a terminal with colors', function () {
	$translation = new Translation;
	$translation->addPreset('dresscode/psr12');
	$printer = new MigrationPrinter($translation, 'a.php', 'x y.neon', 'W:/p/x y.neon', 0);
	Assert::contains('WRITTEN  x y.neon', $printer->print(migrationConsole()));
	Assert::contains(
		"\e]8;;file:///W:/p/x%20y.neon\e\\x y.neon\e]8;;\e\\",
		$printer->print(migrationConsole(ColorDepth::Ansi256, terminal: true)),
	);

	$printer = new MigrationPrinter($translation, 'a.php', 'x.neon', '/home/p/x.neon', 0);
	Assert::contains('file:///home/p/x.neon', $printer->print(migrationConsole(ColorDepth::Ansi256, terminal: true)));
});
