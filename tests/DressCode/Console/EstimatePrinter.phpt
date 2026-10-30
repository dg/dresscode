<?php declare(strict_types=1);

use DressCode\Console\EstimatePrinter;
use DressCode\Upgrade\{BlindSpots, Blocker, Blockers, Estimate, Target};
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
