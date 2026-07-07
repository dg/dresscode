<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\ConfigurationException;
use PhpSyntax\PhpVersion;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('defaults', function () {
	$config = Config::create();
	Assert::same([], $config->getPresets());
	Assert::same([], $config->getRules());
	Assert::same('auto', $config->getPhpVersion());
	Assert::same("\t", $config->getIndent());
	Assert::same('auto', $config->getEol());
	Assert::same([], $config->getPaths());
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'], $config->getSkip());
	Assert::same([], $config->getRuleSkip());
	Assert::same(['php'], $config->getFileExtensions());
	Assert::null($config->getSkipWhen());
	Assert::null($config->getBaseline());
});


test('fluent setters', function () {
	$config = Config::create()
		->preset('a/b')
		->preset(stdClass::class)
		->phpVersion('8.2')
		->enable('x/y', ['opt' => 1])
		->enable('x/z')
		->disable('x/y')
		->style(indent: '    ', eol: "\r\n")
		->paths(['src'])
		->skip(['tests/fixtures/*', 'x/z' => ['tests'], 'x/y' => 'legacy'])
		->fileExtensions(['php', 'phpt'])
		->skipWhen(fn(string $content, string $path) => $path === 'skip.php')
		->baseline('baseline.json');
	Assert::same(['a/b', stdClass::class], $config->getPresets());
	Assert::same(['x/y' => false, 'x/z' => true], $config->getRules());
	Assert::equal(new PhpVersion(8, 2), $config->getPhpVersion());
	Assert::same('    ', $config->getIndent());
	Assert::same("\r\n", $config->getEol());
	Assert::same(['src'], $config->getPaths());
	Assert::same(['tests/fixtures/*'], $config->getSkip());
	Assert::same(['x/z' => ['tests'], 'x/y' => ['legacy']], $config->getRuleSkip());
	Assert::same(['php', 'phpt'], $config->getFileExtensions());
	$skipWhen = $config->getSkipWhen();
	Assert::notNull($skipWhen);
	Assert::true($skipWhen('', 'skip.php'));
	Assert::same('baseline.json', $config->getBaseline());
});


test('validation', function () {
	Assert::exception(fn() => Config::create()->style(eol: 'crlf')->getEol(), ConfigurationException::class);
	Assert::exception(fn() => Config::create()->phpVersion('eight'), InvalidArgumentException::class);
});
