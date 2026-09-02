<?php declare(strict_types=1);

use DressCode\Console\ProgressBar;
use Nette\CommandLine\{Ansi, Console};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

putenv('COLUMNS=80');


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
	return new ProgressBar(new Console($stream, colors: false, terminal: true), 10);
}


test('a run over before the delay draws nothing', function () {
	$stream = stream();
	$bar = bar($stream);
	$bar->advance(3);
	$bar->clear();
	Assert::same('', read($stream));
});


test('the bar shows the share done and the file that takes long', function () {
	$stream = stream();
	$bar = bar($stream);
	usleep(350_000);
	$bar->advance(5, ['src/quick.php' => microtime(as_float: true)]);
	Assert::same("\e[?25l  [==========          ]  5/10\r", read($stream));

	usleep(150_000);
	$bar->advance(6, ['src/slow.php' => microtime(as_float: true) - 3]);
	Assert::contains('6/10  src/slow.php  3s', read($stream));
});


test('a large file nothing is heard of is named at once with its size, until the next one starts', function () {
	$stream = stream();
	$bar = bar($stream);
	$bar->advance(0, ['src/large.php' => microtime(as_float: true)], 250_000);
	Assert::same("\e[?25l  [                    ]  0/10  src/large.php  250 kB\r", read($stream));

	$bar->advance(1, ['src/small.php' => microtime(as_float: true)], 2_000);
	Assert::same(
		"\e[?25l  [                    ]  0/10  src/large.php  250 kB\r"
		. "\e[J  [==                  ]  1/10\r",
		read($stream),
	);
});


test('the path of a file is cut at the front, so that its name stays', function () {
	putenv('COLUMNS=70');
	$stream = stream();
	$bar = bar($stream);
	$bar->advance(0, ['src/DressCode/Rules/Whitespace/LongNameRule.php' => microtime(as_float: true)], 250_000);
	$line = str_replace(["\e[?25l", "\r"], '', read($stream));
	Assert::contains('…', $line); // the front of the path is gone
	Assert::contains('LongNameRule.php  250 kB', $line);
	Assert::same(70, Ansi::measure($line));
	putenv('COLUMNS=80');
});


test('the last file erases the line', function () {
	$stream = stream();
	$bar = bar($stream);
	usleep(350_000);
	$bar->advance(5);
	$bar->advance(10);
	Assert::match('%A%' . "\e[J\e[?25h", read($stream));
});
