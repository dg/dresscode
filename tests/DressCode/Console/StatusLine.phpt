<?php declare(strict_types=1);

use DressCode\Console\StatusLine;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the phase stands with its label and what it does', function () {
	$stream = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$status = new StatusLine(new Console($stream, colorDepth: ColorDepth::None, terminal: true));
	$status->show('Measuring', 'measuring 3 decisions');
	rewind($stream);
	Assert::match("%A%Measuring  measuring 3 decisions…\r", (string) stream_get_contents($stream));
});
