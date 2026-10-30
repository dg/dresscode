<?php declare(strict_types=1);

use DressCode\Console\EstimatePrinter;
use DressCode\Upgrade\{BlindSpots, Blocker, Blockers, Estimate, Move, Target};
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


test('the libraries the data know are broken onto lines that keep the width of the output', function () {
	$known = ['acme/application' => '3.3', 'acme/component-model' => '3.2', 'acme/http' => '3.4', 'acme/utils' => '4.1.5', 'php' => '8.6'];
	$estimate = new Estimate([Target::php('8.4')], [], 1, new BlindSpots, [], [], $known, true, false, []);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains(
		"Data       acme/application up to 3.3, acme/component-model up to 3.2, acme/http up to 3.4,\n"
		. "           acme/utils up to 4.1.5, php up to 8.6\n",
		$out,
	);
});


test('the major moves of the update are said under Moves, those the data do not reach marked', function () {
	$estimate = new Estimate(
		[new Target('acme/utils', '4.0')],
		[],
		1,
		new BlindSpots,
		[],
		[],
		['acme/utils' => '4.1'],
		true,
		false,
		[],
		[new Move('acme/utils', 'v3.2.10', 'v4.0.11'), new Move('acme/schema', 'v1.3.0', 'v2.0.0')],
	);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);
	$out = new EstimatePrinter($estimate)->print($console);

	Assert::contains("Moves      acme/utils v3.2.10 => v4.0.11, acme/schema v1.3.0 => v2.0.0 (no data)\n", $out);
	Assert::same(
		[
			['package' => 'acme/utils', 'from' => 'v3.2.10', 'to' => 'v4.0.11', 'data' => true],
			['package' => 'acme/schema', 'from' => 'v1.3.0', 'to' => 'v2.0.0', 'data' => false],
		],
		new EstimatePrinter($estimate)->toArray()['moves'],
	);
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
		movesUnknown: 'Composer did not answer in time',
	);
	$console = new Console(fopen('php://memory', 'w+') ?: throw new RuntimeException, ColorDepth::None, terminal: false);

	Assert::contains("Moves      unknown, Composer did not answer in time\n", new EstimatePrinter($estimate)->print($console));
});
