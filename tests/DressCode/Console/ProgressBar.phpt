<?php declare(strict_types=1);

use DressCode\Console\ProgressBar;
use Nette\CommandLine\Console;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return resource */
function stream()
{
	return fopen('php://memory', 'w+') ?: throw new RuntimeException;
}


/** @param resource $stream */
function read($stream): string
{
	rewind($stream);
	return (string) stream_get_contents($stream);
}


/** @param resource $stream */
function bar($stream): ProgressBar
{
	$console = new Console;
	$console->useColors(false);
	return new ProgressBar($stream, $console, 10);
}


test('a run over before the delay draws nothing', function () {
	$stream = stream();
	$bar = bar($stream);
	$bar->advance(3);
	$bar->finish();
	Assert::same('', read($stream));
});


test('the bar shows the share done and the file that takes long', function () {
	$stream = stream();
	$bar = bar($stream);
	usleep(350_000);
	$bar->advance(5, ['src/quick.php' => microtime(as_float: true)]);
	Assert::same("\r  [==========          ]  5/10\r", read($stream));

	usleep(150_000);
	$bar->advance(6, ['src/slow.php' => microtime(as_float: true) - 3]);
	Assert::contains('6/10  src/slow.php  3s', read($stream));
});


test('the last file erases the line', function () {
	$stream = stream();
	$bar = bar($stream);
	usleep(350_000);
	$bar->advance(5);
	$bar->advance(10);
	Assert::match('%A%' . str_repeat(' ', 30) . "\r", read($stream));
});
