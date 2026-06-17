<?php declare(strict_types=1);

use DressCode\{Config, NodeRule, Override, Profile};
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


#[DressCode\RuleInfo(DressCode\Stage::Structure)]
final class ConfigTestRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


test('defaults', function () {
	$config = new Config;
	Assert::same([], $config->use);
	Assert::same([], $config->plugins);
	Assert::same([], $config->rules);
	Assert::same([], $config->targets);
	Assert::same(['functions' => [], 'constants' => []], $config->namespaces);
	Assert::null($config->nameResolution);
	Assert::same([], $config->fixRisky);
	Assert::same([], $config->warnOnly);
	Assert::same([], $config->overrides);
	Assert::same([], $config->paths);
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'], $config->excludePaths);
	Assert::same(['php'], $config->fileExtensions);
	Assert::null($config->skipWhen);
	Assert::same([], $config->analyses);
});


test('every key is a named argument', function () {
	$override = new Override(['tests'], new Profile(use: ['x/y']));
	$factory = fn() => new ConfigTestRule;
	$config = new Config(
		use: ['a/b'],
		rules: [ConfigTestRule::class],
		targets: ['php' => '8.2'],
		overrides: [$override],
		paths: ['src'],
		fileExtensions: ['php', 'phpt'],
		skipWhen: fn(string $content, string $path) => $path === 'skip.php',
	);
	Assert::same(['a/b'], $config->use);
	Assert::same([ConfigTestRule::class => null], $config->rules);
	Assert::same([ConfigTestRule::class => $factory], new Config(rules: [ConfigTestRule::class => $factory])->getRuleFactories());
	Assert::same('8.2', $config->targets['php'] ?? null);
	Assert::same([$override], $config->overrides);
	Assert::same(['src'], $config->paths);
	Assert::same(['php', 'phpt'], $config->fileExtensions);
	Assert::true(($config->skipWhen ?? throw new LogicException)('', 'skip.php'));
});


test('an override is a profile for the paths it names', function () {
	$profile = new Profile(nameResolution: 'uncertain');
	$override = new Override(['tests'], $profile);
	Assert::same(['tests'], $override->paths);
	Assert::same($profile, $override->profile);
	Assert::exception(fn() => new Override([], new Profile), InvalidArgumentException::class, 'An override needs the paths it applies to.');
	Assert::same(['php' => '8.3'], new Override(['tests'], new Profile(targets: ['php' => '8.3']))->profile->targets);
});


test('excluded paths add up to the default list, each pattern once', function () {
	Assert::same(['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*', 'build'], new Config(excludePaths: ['build', 'vendor'])->excludePaths);
});


test('a pattern of paths that is no glob is refused when it is written', function () {
	$message = 'Pattern `%a%` of `%a%` is not a valid glob: a character class `[...]` is not closed or its range runs backwards.';
	Assert::exception(fn() => new Config(excludePaths: ['[broken']), InvalidArgumentException::class, $message);
	Assert::exception(fn() => new Override(['src/[z-a]'], new Profile), InvalidArgumentException::class, $message);
	Assert::exception(fn() => new DressCode\PluginManifest(excludePaths: ['[']), InvalidArgumentException::class, $message);
	Assert::noError(fn() => new Override(['src/[a-z]*', 'tests/[!.]*'], new Profile));
});


test('a value a profile cannot hold is refused when it is written', function () {
	Assert::exception(fn() => new Profile(targets: ['php' => 'eight']), InvalidArgumentException::class, 'The PHP version must be written as `8.2`, `eight` given.');
	Assert::exception(fn() => new Profile(nameResolution: 'sure'), InvalidArgumentException::class, 'The name resolution must be `certain` or `uncertain`, `sure` given.');
	Assert::exception(fn() => new Profile(namespaces: ['classes' => []]), InvalidArgumentException::class, 'The namespaces declare functions and constants, not `classes`.');
	Assert::exception(fn() => new Config(rules: ['x/y']), InvalidArgumentException::class, '`rules` names the classes of the rules of the project, `x/y` is not one.'); // @phpstan-ignore argument.type (the check is the point)
	Assert::exception(fn() => new Config(rules: [ConfigTestRule::class => true]), InvalidArgumentException::class, 'The factory of rule `ConfigTestRule` must be a closure, `bool` given.'); // @phpstan-ignore argument.type (the check is the point)
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
		'`strlen` is in no namespace, and a global function needs no listing.',
	);
	Assert::exception(
		fn() => new Profile(namespaces: ['constants' => ['App\X as Y']]),
		InvalidArgumentException::class,
		'`App\\X as Y` gives the const an alias, which says nothing about what a namespace declares.',
	);
	Assert::exception(
		fn() => new Profile(namespaces: ['functions' => ['App\a; echo 1']]),
		InvalidArgumentException::class,
		'`App\\a; echo 1` is not a name the way a `use function` statement writes it: `use function App\\a; echo 1;` is not a single statement.',
	);
});


test('an analysis is a class the engine builds itself, or a class with its factory', function () {
	$factory = fn() => new ArrayObject;
	$config = new Config(analyses: [stdClass::class, ArrayObject::class => $factory]);
	Assert::same([stdClass::class => null, ArrayObject::class => $factory], $config->analyses);

	Assert::exception(
		fn() => new Config(analyses: ['DressCode\Missing']),
		InvalidArgumentException::class,
		'Analysis class `DressCode\Missing` does not exist.',
	);
	Assert::exception(
		fn() => new Config(analyses: [ArrayObject::class]),
		InvalidArgumentException::class,
		'Analysis `ArrayObject` must take the `FileNode` or nothing in its constructor, or come with a factory.',
	);
});
