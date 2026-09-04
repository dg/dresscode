<?php declare(strict_types=1);

use DressCode\{Config, Override, Profile};
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('defaults', function () {
	$config = new Config;
	Assert::same([], $config->extensions);
	Assert::same([], $config->presets);
	Assert::same([], $config->rules);
	Assert::null($config->php);
	Assert::null($config->indent);
	Assert::null($config->eol);
	Assert::same(['functions' => [], 'constants' => []], $config->namespaces);
	Assert::null($config->nameResolution);
	Assert::same([], $config->fixRisky);
	Assert::same([], $config->warnings);
	Assert::same([], $config->overrides);
	Assert::same([], $config->paths);
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'], $config->excludePaths);
	Assert::same(['php'], $config->fileExtensions);
	Assert::null($config->skipWhen);
	Assert::null($config->baseline);
	Assert::null($config->cacheDir);
	Assert::same([], $config->analyses);
	Assert::null($config->types);
});


test('every key is a named argument', function () {
	$override = new Override(['tests'], rules: ['x/y' => true]);
	$config = new Config(
		presets: ['a/b'],
		rules: ['x/y' => ['opt' => 1], 'x/z' => false],
		indent: 4,
		eol: 'CRLF',
		php: '8.2',
		overrides: [$override],
		paths: ['src'],
		fileExtensions: ['php', 'phpt'],
		skipWhen: fn(string $content, string $path) => $path === 'skip.php',
		baseline: 'baseline.json',
	);
	Assert::same(['a/b'], $config->presets);
	Assert::same(['x/y' => ['opt' => 1], 'x/z' => false], $config->rules);
	Assert::same([4, 'CRLF', '8.2'], [$config->indent, $config->eol, $config->php]);
	Assert::same([$override], $config->overrides);
	Assert::same(['src'], $config->paths);
	Assert::same(['php', 'phpt'], $config->fileExtensions);
	Assert::true(($config->skipWhen ?? throw new LogicException)('', 'skip.php'));
	Assert::same('baseline.json', $config->baseline);
});


test('an override is a profile for the paths it names', function () {
	$override = new Override(['tests'], presets: ['nette'], nameResolution: 'uncertain', fixRisky: ['strict-call']);
	Assert::same(['tests'], $override->paths);
	Assert::same(['nette'], $override->presets);
	Assert::same('uncertain', $override->nameResolution);
	Assert::same(['strict-call'], $override->fixRisky);
	Assert::exception(fn() => new Override([]), InvalidArgumentException::class, 'An override needs the paths it applies to.');
});


test('excluded paths add up to the default list, each pattern once', function () {
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'build'], new Config(excludePaths: ['build', 'vendor'])->excludePaths);
});


test('a value a profile cannot hold is refused when it is written', function () {
	Assert::exception(fn() => new Config(eol: 'unix'), InvalidArgumentException::class, "The line ending must be 'LF', 'CRLF', 'majority' or 'platform'.");
	Assert::exception(fn() => new Profile(eol: 'crlf'), InvalidArgumentException::class, "The line ending must be 'LF', 'CRLF', 'majority' or 'platform'.");
	Assert::exception(fn() => new Profile(indent: 'spaces'), InvalidArgumentException::class, "The indentation must be a number of spaces or 'tab'.");
	Assert::exception(fn() => new Override(['tests'], indent: 0), InvalidArgumentException::class, "The indentation must be a number of spaces or 'tab'.");
	Assert::exception(fn() => new Profile(php: 'eight'), InvalidArgumentException::class, "The PHP version must be written as '8.2', 'eight' given.");
	Assert::exception(fn() => new Profile(nameResolution: 'sure'), InvalidArgumentException::class, "The name resolution must be 'certain' or 'uncertain', 'sure' given.");
	Assert::exception(fn() => new Profile(namespaces: ['classes' => []]), InvalidArgumentException::class, "The namespaces declare functions and constants, not 'classes'.");
	Assert::exception(fn() => new Profile(types: 'psalm'), InvalidArgumentException::class, "The types must be 'phpstan', 'psalm' given.");
});


test('what the namespaces declare is written as a use statement writes it', function () {
	$profile = new Profile(namespaces: ['functions' => ['App\helper', '\App\Utils\format', 'App\Utils\{parse, dump}'], 'constants' => ['App\{A, B}']]);
	Assert::same(['App\helper', 'App\Utils\format', 'App\Utils\parse', 'App\Utils\dump'], $profile->namespaces['functions']);
	Assert::same(['App\A', 'App\B'], $profile->namespaces['constants']);
});


test('a name that says nothing about what a namespace declares is refused', function () {
	Assert::exception(
		fn() => new Profile(namespaces: ['functions' => ['strlen']]),
		InvalidArgumentException::class,
		"'strlen' is in no namespace, and a global function needs no listing.",
	);
	Assert::exception(
		fn() => new Profile(namespaces: ['constants' => ['App\X as Y']]),
		InvalidArgumentException::class,
		"'App\\X as Y' gives the const an alias, which says nothing about what a namespace declares.",
	);
	Assert::exception(
		fn() => new Profile(namespaces: ['functions' => ['App\a; echo 1']]),
		InvalidArgumentException::class,
		"'App\\a; echo 1' is not a name the way a use function statement writes it: The code is not a single statement.",
	);
});


test('an analysis is a class the engine builds itself, or a class with its factory', function () {
	$factory = fn() => new ArrayObject;
	$config = new Config(analyses: [stdClass::class, ArrayObject::class => $factory]);
	Assert::same([stdClass::class => null, ArrayObject::class => $factory], $config->analyses);

	Assert::exception(
		fn() => new Config(analyses: ['DressCode\Missing']),
		InvalidArgumentException::class,
		'Analysis class DressCode\Missing does not exist.',
	);
	Assert::exception(
		fn() => new Config(analyses: [ArrayObject::class]),
		InvalidArgumentException::class,
		'Analysis ArrayObject must take the FileNode or nothing in its constructor, or come with a factory.',
	);
});
