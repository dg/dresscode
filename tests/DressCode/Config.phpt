<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\ConfigurationException;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


final class ConfigInnerExtension
{
	public function __invoke(Config $config): void
	{
		$config->preset('inner');
	}
}


final class ConfigOuterExtension
{
	public function __invoke(Config $config): void
	{
		$config->extension(ConfigInnerExtension::class)->preset('outer');
	}
}


test('defaults', function () {
	$config = Config::create();
	Assert::same([], $config->getPresets());
	Assert::same([], $config->getRules());
	Assert::same('auto', $config->getPhp());
	Assert::null($config->getIndent());
	Assert::null($config->getEol());
	Assert::same([], $config->getPaths());
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'], $config->getExcludePaths());
	Assert::same([], $config->getRuleExcludePaths());
	Assert::same(['php'], $config->getFileExtensions());
	Assert::null($config->getSkipWhen());
	Assert::null($config->getBaseline());
});


test('fluent setters', function () {
	$config = Config::create()
		->preset('a/b')
		->preset(stdClass::class)
		->php('8.2')
		->enable('x/y', ['opt' => 1])
		->enable('x/z')
		->disable('x/y')
		->indent(4)
		->eol('CRLF')
		->paths(['src'])
		->excludePaths(['tests/fixtures/*'])
		->excludeRulePaths('x/z', ['tests'])
		->excludeRulePaths('x/y', ['legacy'])
		->fileExtensions(['php', 'phpt'])
		->skipWhen(fn(string $content, string $path) => $path === 'skip.php')
		->baseline('baseline.json');
	Assert::same(['a/b', stdClass::class], $config->getPresets());
	Assert::same(['x/y' => false, 'x/z' => true], $config->getRules());
	Assert::same('8.2', $config->getPhp());
	Assert::same(4, $config->getIndent());
	Assert::same('CRLF', $config->getEol());
	Assert::same(['src'], $config->getPaths());
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'tests/fixtures/*'], $config->getExcludePaths());
	Assert::same(['x/z' => ['tests'], 'x/y' => ['legacy']], $config->getRuleExcludePaths());
	Assert::same(['php', 'phpt'], $config->getFileExtensions());
	$skipWhen = $config->getSkipWhen();
	Assert::notNull($skipWhen);
	Assert::true($skipWhen('', 'skip.php'));
	Assert::same('baseline.json', $config->getBaseline());
});


test('excluded paths add up to the default list, each pattern once', function () {
	$config = Config::create()->excludePaths(['build'])->excludeRulePaths('x/y', ['legacy']);
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'build'], $config->getExcludePaths());
	Assert::same(['x/y' => ['legacy']], $config->getRuleExcludePaths());
	$config->excludePaths(['dist', 'build'])->excludeRulePaths('x/y', ['old']);
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'build', 'dist'], $config->getExcludePaths());
	Assert::same(['x/y' => ['legacy', 'old']], $config->getRuleExcludePaths());
});


test('validation', function () {
	Assert::exception(fn() => Config::create()->eol('unix')->getEol(), ConfigurationException::class);
	Assert::exception(fn() => Config::create()->eol('crlf')->getEol(), ConfigurationException::class);
	Assert::exception(fn() => Config::create()->indent('spaces')->getIndent(), ConfigurationException::class);
	Assert::exception(fn() => Config::create()->php('eight'), InvalidArgumentException::class);
});


test('a layer overrides what it sets, appends presets and rules and adds to the exclusions', function () {
	$base = Config::create()->preset('a')->enable('x', ['a' => 1])->enable('y')->paths(['src'])->excludePaths(['build'])->excludeRulePaths('x', ['legacy'])->indent(2);
	$layer = Config::create()->preset('b')->disable('x')->enable('z')->excludePaths(['dist'])->excludeRulePaths('x', ['old'])->eol('LF');
	$base->merge($layer);
	Assert::same(['a', 'b'], $base->getPresets());
	Assert::same(['x' => false, 'y' => true, 'z' => true], $base->getRules());
	Assert::same(['src'], $base->getPaths());
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'build', 'dist'], $base->getExcludePaths());
	Assert::same(['x' => ['legacy', 'old']], $base->getRuleExcludePaths());
	Assert::same(2, $base->getIndent());
	Assert::same('LF', $base->getEol());
});


test('an extension sets up a layer below the configuration, whatever the order of the calls', function () {
	$config = Config::create()
		->indent('tab')
		->preset('project')
		->extension(fn(Config $config) => $config->preset('extension')->indent(2)->excludePaths(['expected']))
		->excludePaths(['build'])
		->resolveExtensions();
	Assert::same(['extension', 'project'], $config->getPresets());
	Assert::same('tab', $config->getIndent());
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'expected', 'build'], $config->getExcludePaths());
	Assert::same([], $config->getExtensions());
});


test('an extension named by its class is applied once, the ones it pulls in before it', function () {
	$config = Config::create()
		->extension(ConfigOuterExtension::class)
		->extension(ConfigInnerExtension::class)
		->resolveExtensions();
	Assert::same(['inner', 'outer'], $config->getPresets());
});


test('an extension that cannot be called says so', function () {
	Assert::exception(
		fn() => Config::create()->extension('DressCode\Missing')->resolveExtensions(),
		ConfigurationException::class,
		'Extension class DressCode\Missing does not exist.',
	);
	Assert::exception(
		fn() => Config::create()->extension(stdClass::class)->resolveExtensions(),
		ConfigurationException::class,
		'Extension stdClass is not callable, it needs an __invoke() method.',
	);
});


test('an analysis the engine cannot build says so', function () {
	Assert::exception(
		fn() => Config::create()->analysis(ArrayObject::class),
		ConfigurationException::class,
		'Analysis ArrayObject must take the FileNode or nothing in its constructor, or come with a factory.',
	);

	// a constructor the engine can call, or a factory that calls it instead
	Assert::noError(fn() => Config::create()->analysis(stdClass::class));
	Assert::noError(fn() => Config::create()->analysis(ArrayObject::class, fn() => new ArrayObject));
});
