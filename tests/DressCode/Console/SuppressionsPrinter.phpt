<?php declare(strict_types=1);

use DressCode\Console\SuppressionsPrinter;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function suppressionsConsole(): Console
{
	return new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
}


test('places are shortened and the names counted beyond five, which the output says where to read in full', function () {
	$unknown = array_fill_keys(['A', 'B', 'C', 'D', 'E', 'F'], ['a.php:1']);
	$unknown['A'] = ['a.php:1', 'a.php:2', 'b.php:3'];
	Assert::same(
		"Of the 9 comments rewritten:\n"
		. "8  name a rule DressCode does not know, which silences nothing: `A` (a.php:1 and 2 more), `B` (a.php:1), `C` (a.php:1), `D` (a.php:1), `E` (a.php:1) and 1 more\n"
		. "\n"
		. "`--format json` lists all the places.\n"
		. "Next, replace or remove those names; then `dresscode check` shows the violations left.\n"
		. "\n"
		. "REWRITTEN  9 comments in 2 files\n",
		new SuppressionsPrinter(9, 2, false, $unknown, [], [])->print(suppressionsConsole()),
	);
});


test('the comments reaching further are checked, in plural and shortened', function () {
	Assert::same(
		"Of the 2 comments rewritten:\n"
		. "2  now silence the whole statement below them, not just the next line, so they may hide more than you meant (a.php:1 and 1 more)\n"
		. "\n"
		. "`--format json` lists all the places.\n"
		. "Next, check what those comments hide; then `dresscode check` shows the violations left.\n"
		. "\n"
		. "REWRITTEN  2 comments in 1 file\n",
		new SuppressionsPrinter(2, 1, false, [], ['a.php:1', 'a.php:4'], [])->print(suppressionsConsole()),
	);
});


test('a dry run writes nothing and advises nothing to do, a run with nothing to look at only says the result', function () {
	Assert::same(
		"Of the 1 comment to rewrite:\n"
		. "1  now silences the whole statement below it, not just the next line, so it may hide more than you meant (a.php:1)\n"
		. "\n"
		. "Would rewrite 1 comment in 1 file, writing nothing.\n",
		new SuppressionsPrinter(1, 1, true, [], ['a.php:1'], [])->print(suppressionsConsole()),
	);
	Assert::same("REWRITTEN  3 comments in 2 files\n", new SuppressionsPrinter(3, 2, false, [], [], [])->print(suppressionsConsole()));
	Assert::same("OK  no phpcs comments to rewrite\n", new SuppressionsPrinter(0, 0, false, [], [], [])->print(suppressionsConsole()));
});
