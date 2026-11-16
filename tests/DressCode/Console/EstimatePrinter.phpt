<?php declare(strict_types=1);

use DressCode\Console\EstimatePrinter;
use DressCode\Upgrade\{BlindSpots, Estimate, Phase, Target, Topic};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a group DressCode fixes whole says so, and an estimate that no run follows says it changed nothing', function () {
	$topic = new Topic('acme/utils', 'deprecations', Phase::Jump, 1, ['automatic' => 2], '^4.0');
	$estimate = new Estimate([Target::php('8.4')], [$topic], 1, new BlindSpots, [], [], true, false, []);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains('an estimate: nothing was changed', $out);
	Assert::contains("Must change, 2 findings, all fixed by DressCode\n", $out);
	Assert::contains('fixed by DressCode, once ^4.0 is installed', $out);
});


test('the libraries the data know are broken onto lines that keep the width of the output', function () {
	$known = ['acme/application' => '3.3', 'acme/component-model' => '3.2', 'acme/http' => '3.4', 'acme/utils' => '4.1.5', 'php' => '8.6'];
	$estimate = new Estimate([Target::php('8.4')], [], 1, new BlindSpots, [], $known, true, false, []);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains(
		"Rules for  `acme/application` up to 3.3, `acme/component-model` up to 3.2, `acme/http` up to 3.4,\n"
		. "           `acme/utils` up to 4.1.5, `php` up to 8.6\n",
		$out,
	);
});


test('the files the check could not process are said, their places not counted', function () {
	$estimate = new Estimate(
		[Target::php('8.4')],
		[],
		2,
		new BlindSpots,
		[],
		[],
		true,
		false,
		[],
		unprocessed: ['src/Broken.php' => 'Syntax error: x'],
	);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);

	Assert::contains(
		"Unverified 1 file the check could not process, whose findings are not counted: src/Broken.php\n",
		new EstimatePrinter($estimate)->print($console),
	);
	Assert::same([['file' => 'src/Broken.php', 'message' => 'Syntax error: x']], new EstimatePrinter($estimate)->toArray()['unverified']);
});
