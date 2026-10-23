<?php declare(strict_types=1);

use DressCode\Console\StatusLine;
use Nette\CommandLine\{ColorDepth, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a spinner is a frame of a star for this moment, the progress beside it in its color', function () {
	Assert::match('~^[·✢✶✻✽]  3/12$~u', StatusLine::formatSpinner(new Console(colorDepth: ColorDepth::None), '3/12'));
	Assert::match('~^[·✢✶✻✽]$~u', StatusLine::formatSpinner(new Console(colorDepth: ColorDepth::None)));
	Assert::match("~^\e\\[36m[·✢✶✻✽]  3/12\e\\[0m$~u", StatusLine::formatSpinner(new Console(colorDepth: ColorDepth::Ansi256), '3/12'));
});


test('the phase stands with its label, the spinner and the count of its steps beside it', function () {
	$stream = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$status = new StatusLine(new Console($stream, colorDepth: ColorDepth::None, terminal: true));
	$status->show('Measuring', 'measuring 3 decisions');
	$status->advance(1, 4);
	rewind($stream);
	Assert::match(
		"%A%Measuring  measuring 3 decisions…\r%A%Measuring  measuring 3 decisions…  %a%  1/4\r",
		(string) stream_get_contents($stream),
	);
});
