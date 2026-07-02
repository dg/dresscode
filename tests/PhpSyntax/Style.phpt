<?php declare(strict_types=1);

use PhpSyntax\PhpVersion;
use PhpSyntax\Style;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('PhpVersion', function () {
	$version = PhpVersion::fromString('8.2');
	Assert::same([8, 2], [$version->major, $version->minor]);
	Assert::same(80200, $version->getId());
	Assert::same('8.2', (string) $version);
	Assert::same('8.4', (string) PhpVersion::fromString('8.4.11'));
	Assert::same('8.1', (string) PhpVersion::fromId(80105));
	Assert::same(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, (string) PhpVersion::current());

	Assert::true($version->isAtLeast('8.2'));
	Assert::true($version->isAtLeast('7.4'));
	Assert::false($version->isAtLeast(new PhpVersion(8, 3)));
	Assert::exception(fn() => PhpVersion::fromString('8'), InvalidArgumentException::class, "Invalid PHP version '8'.");
});


test('Style defaults, detected line ending and derived styles', function () {
	$style = new Style;
	Assert::same(["\t", "\n", 4], [$style->indent, $style->eol, $style->tabWidth]);
	Assert::same("\t\t", $style->indent(2));

	Assert::same("\n", Style::detectEol(''));
	Assert::same("\n", Style::detectEol("a\nb\r\n"));
	Assert::same("\r\n", Style::detectEol("a\r\nb\r\nc\n"));

	$windows = $style->withEol("\r\n")->withIndent('    ');
	Assert::same(["\r\n", '    ', "\t"], [$windows->eol, $windows->indent, $style->indent]);
});
