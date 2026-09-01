<?php declare(strict_types=1);

use DressCode\Console\Markup;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the code in backticks is drawn in its own color, the rest in the given one', function () {
	$console = new Console(colorDepth: ColorDepth::Ansi256);
	Assert::same(
		"Run \e[38;5;117mdresscode init\e[0m first.",
		Markup::highlightCode($console, 'Run `dresscode init` first.'),
	);
	Assert::same(
		"\e[93mWarning: \e[0m\e[38;5;117mx\e[0m\e[93m is old.\e[0m",
		Markup::highlightCode($console, 'Warning: `x` is old.', 'yellow'),
	);
	Assert::same('a lone ` stays', Markup::highlightCode($console, 'a lone ` stays'));
	Assert::same("a ` across\na line `", Markup::highlightCode($console, "a ` across\na line `"));
});


test('code holding a backtick is a span of a longer run, as Helpers::formatCode() writes it', function () {
	$console = new Console(colorDepth: ColorDepth::Ansi256);
	Assert::same('``$a = `ls`;``', DressCode\Helpers::formatCode('$a = `ls`;'));
	Assert::same('`` `ls` ``', DressCode\Helpers::formatCode('`ls`'));
	Assert::same('`$a`', DressCode\Helpers::formatCode('$a'));
	Assert::same("`''`", DressCode\Helpers::formatCode('')); // Markdown cannot mark empty code
	Assert::same(
		"The line \e[38;5;117m\$a = `ls`;\e[0m is forbidden",
		Markup::highlightCode($console, 'The line ' . DressCode\Helpers::formatCode('$a = `ls`;') . ' is forbidden'),
	);
	Assert::same(
		"Line \e[38;5;117m`ls`\e[0m and \e[38;5;117mx\e[0m",
		Markup::highlightCode($console, 'Line ' . DressCode\Helpers::formatCode('`ls`') . ' and `x`'),
	);
});


test('without colors the text stays as it is', function () {
	$console = new Console(colorDepth: ColorDepth::None);
	Assert::same('Run `dresscode init` first.', Markup::highlightCode($console, 'Run `dresscode init` first.', 'red'));
	Assert::same('See https://dresscode.run/cli#init', Markup::formatDocsLink($console, 'cli#init'));
	Assert::same("-a\n+b\n", Markup::highlightDiff($console, "-a\n+b\n"));
});


test('the page of the manual is a link', function () {
	Assert::same(
		"\e[90mSee \e]8;;https://dresscode.run/cli#init\e\\https://dresscode.run/cli#init\e]8;;\e\\\e[0m",
		Markup::formatDocsLink(new Console(colorDepth: ColorDepth::Ansi256, terminal: true), 'cli#init'),
	);
});


test('a diff is drawn by its lines', function () {
	Assert::same(
		"\e[36m@@ -1 +1 @@\n\e[0m\e[91m-a\n\e[0m\e[32m+b\n\e[0m c\n",
		Markup::highlightDiff(new Console(colorDepth: ColorDepth::Ansi256), "@@ -1 +1 @@\n-a\n+b\n c\n"),
	);
});
