<?php declare(strict_types=1);

/**
 * The decisions a configuration writes: sections beside the keys of the configuration, laid over what it uses,
 * a preset by its name or a file by its path, and narrowed by the run without a value changed.
 */

use DressCode\{Config, ConfigurationException, Profile};
use DressCode\Config\{ConfigResolver, Loader, PluginRegistry};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param  array<string, string>  $files  name => content */
function createDecisionsProject(array $files): string
{
	$dir = createTempDir('decisions');
	foreach ($files as $name => $content) {
		file_put_contents("$dir/$name", $content);
	}

	return $dir;
}


/** @param  ?list<string>  $only */
function resolveFile(string $file, ?Profile $commandLine = null, ?array $only = null): DressCode\Config\ResolvedConfig
{
	return new ConfigResolver(new PluginRegistry)->resolve(Loader::loadFile($file), '8.3', commandLine: $commandLine, only: $only);
}


test('a file that comes back to itself is an error, and so is one that does not exist', function () {
	$dir = createDecisionsProject([
		'a.neon' => "use: b.neon\n",
		'b.neon' => "use: a.neon\n",
		'dresscode.neon' => "use: a.neon\n",
	]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Preset file `%a%/a.neon` uses itself through `%a%/b.neon`; a preset cannot come back to itself.');

	$dir = createDecisionsProject(['dresscode.neon' => "use: missing.neon\n"]);
	Assert::exception(fn() => Loader::loadFile("$dir/dresscode.neon"), ConfigurationException::class, 'Configuration file `%a%`: Preset file `missing.neon` in `use` does not exist.');
	Assert::exception(
		fn() => new ConfigResolver(new PluginRegistry, root: $dir)->resolve(new Config(overrides: [new DressCode\Override(['tests'], new DressCode\Profile(use: ['missing.neon']))]), '8.3'),
		ConfigurationException::class,
		'The override for `tests`: Preset file `missing.neon` in `use` does not exist.',
	);
});


test('a preset written as a file carries decisions and what it uses, nothing the project decides', function () {
	$dir = createDecisionsProject(['base.neon' => "paths: [src]\n", 'dresscode.neon' => "use: base.neon\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Preset file `%a%/base.neon` sets `paths`, which the project decides, not a preset.');
	$dir = createDecisionsProject(['base.neon' => "use: [[psr12]]\n", 'dresscode.neon' => "use: base.neon\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, "Preset file `%a%/base.neon`: The item 'use%a%0' expects to be string|Nette\\Neon\\Entity, array given.");
});


test('a key the catalogue does not know is named with the nearest known one, where it stands', function () {
	$dir = createDecisionsProject(['dresscode.neon' => "spacng:\n\tcall: \"foo()\"\n"]);
	Assert::exception(fn() => resolveFile("$dir/dresscode.neon"), ConfigurationException::class, 'Key `spacng` is unknown; write `spacing`.');
});
