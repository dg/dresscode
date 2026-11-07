<?php declare(strict_types=1);

use DressCode\Console\EstimatePrinter;
use DressCode\Upgrade\{BlindSpots, Blocker, Blockers, Estimate, Move, Moves, Phase, Target, Topic};
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('what the lock refuses a target with is said under Composer, and so is a lock refusing nothing', function () {
	$estimate = new Estimate(
		[new Target('acme/mail', '4.0'), Target::php('8.4')],
		[],
		1,
		new BlindSpots,
		[
			new Blockers(new Target('acme/mail', '4.0'), [new Blocker('acme/shop', 'v1.0.0', '^3.2')]),
			new Blockers(Target::php('8.4')),
		],
		[],
		[],
		true,
		false,
		[],
	);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains("Composer   acme/mail 4.0: refused by acme/shop v1.0.0 (^3.2)\n", $out);
	Assert::contains("Composer   php 8.4: nothing in the lock refuses it\n", $out);
});


test('a group DressCode fixes whole says so, and an estimate that no run follows says it changed nothing', function () {
	$topic = new Topic('acme/utils', 'deprecations', Phase::Jump, 1, ['automatic' => 2], '^4.0');
	$estimate = new Estimate([Target::php('8.4')], [$topic], 1, new BlindSpots, [], [], [], true, false, []);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains('an estimate: nothing was changed', $out);
	Assert::contains("Must change, 2 findings, all fixed by DressCode\n", $out);
	Assert::contains('fixed by DressCode, once ^4.0 is installed', $out);
});


test('the libraries the data know are broken onto lines that keep the width of the output', function () {
	$known = ['acme/application' => '3.3', 'acme/component-model' => '3.2', 'acme/http' => '3.4', 'acme/utils' => '4.1.5', 'php' => '8.6'];
	$estimate = new Estimate([Target::php('8.4')], [], 1, new BlindSpots, [], [], $known, true, false, []);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains(
		"Rules for  `acme/application` up to 3.3, `acme/component-model` up to 3.2, `acme/http` up to 3.4,\n"
		. "           `acme/utils` up to 4.1.5, `php` up to 8.6\n",
		$out,
	);
});


test('the major moves of the update are said under Versions, those the data do not reach marked, the data listing every move', function () {
	$estimate = new Estimate(
		[new Target('acme/utils', '4.0')],
		[],
		1,
		new BlindSpots,
		[],
		[],
		['acme/utils' => '4.1', 'acme/cache' => '2.1'],
		true,
		false,
		[],
		new Moves([
			new Move('acme/utils', 'v3.2.10', 'v4.0.11'),
			new Move('acme/http', 'v3.1.0', 'v3.2.0'),
			new Move('acme/schema', 'v1.3.0', 'v2.0.0'),
			new Move('acme/cache', 'v1.4.0', 'v2.3.0'),
		]),
	);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains(
		"Versions   `acme/utils` v3.2.10 => v4.0.11, `acme/schema` v1.3.0 => v2.0.0 (no data),\n           `acme/cache` v1.4.0 => v2.3.0 (data up to 2.1)\n",
		$out,
	);
	Assert::same(
		[
			['package' => 'acme/utils', 'from' => 'v3.2.10', 'to' => 'v4.0.11', 'major' => true, 'data' => true, 'dataUpTo' => '4.1'],
			['package' => 'acme/http', 'from' => 'v3.1.0', 'to' => 'v3.2.0', 'major' => false, 'data' => false, 'dataUpTo' => null],
			['package' => 'acme/schema', 'from' => 'v1.3.0', 'to' => 'v2.0.0', 'major' => true, 'data' => false, 'dataUpTo' => null],
			['package' => 'acme/cache', 'from' => 'v1.4.0', 'to' => 'v2.3.0', 'major' => true, 'data' => false, 'dataUpTo' => '2.1'],
		],
		new EstimatePrinter($estimate)->toArray()['moves']['packages'],
	);

	// with colors the printer draws its code itself, no backtick left for a second drawing
	$colored = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::Ansi256, terminal: false);
	$out = new EstimatePrinter($estimate)->print($colored);
	Assert::contains("\e[", $out);
	Assert::notContains('`', Nette\CommandLine\Ansi::strip($out));
});


test('the files the check could not process are said, their places not counted', function () {
	$estimate = new Estimate(
		[Target::php('8.4')],
		[],
		2,
		new BlindSpots,
		[],
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


test('the moves Composer could not say are unknown, with the reason', function () {
	$estimate = new Estimate(
		[new Target('acme/utils', '4.0')],
		[],
		1,
		new BlindSpots,
		[],
		[],
		[],
		true,
		false,
		[],
		new Moves(unknown: 'Composer did not answer in time'),
	);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);

	Assert::contains("Versions   unknown, Composer did not answer in time\n", new EstimatePrinter($estimate)->print($console));
});
