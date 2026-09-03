<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\ConfigLoader;
use DressCode\ConfigurationException;
use DressCode\Presets\Per;
use Tester\Assert;
use Tester\FileMock;
use Tester\Helpers;

require __DIR__ . '/../../bootstrap.php';

$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


test('the file is searched upwards from the directory', function () use ($fixtures) {
	Assert::same("$fixtures/project/dresscode.php", ConfigLoader::find("$fixtures/project/src/sub"));
	Assert::same("$fixtures/project/dresscode.php", ConfigLoader::find("$fixtures\\project\\"));
	Assert::null(ConfigLoader::find(sys_get_temp_dir()));
});


test('load: the found file and its directory as the root', function () use ($fixtures) {
	[$config, $root] = (new ConfigLoader)->load(null, "$fixtures/project/src/sub");
	Assert::same("$fixtures/project", $root);
	Assert::same(['test/a' => true], $config->getRules());
	Assert::same([], $config->getPresets());
	Assert::same(['src'], $config->getPaths());
});


test('load: an explicit file is taken wherever the run started', function () use ($fixtures) {
	[$config, $root, $file] = (new ConfigLoader)->load("$fixtures/project/dresscode.php", sys_get_temp_dir());
	Assert::same("$fixtures/project", $root);
	Assert::same("$fixtures/project/dresscode.php", $file);
	Assert::same(['test/a' => true], $config->getRules());
});


test('load: without a file the default applies, resolved, and the directory is the root', function () {
	$dir = sys_get_temp_dir();
	[$config, $root, $file] = (new ConfigLoader)->load(null, $dir);
	Assert::same([Per::class], $config->getPresets());
	Assert::same(rtrim(str_replace('\\', '/', $dir), '/'), $root);
	Assert::null($file);

	$default = Config::create()->extension(fn(Config $config) => $config->preset('from/extension'));
	[$config] = (new ConfigLoader)->load(null, $dir, $default);
	Assert::same(['from/extension'], $config->getPresets());
	Assert::same([], $default->getPresets()); // the default itself is left untouched
});


test('errors', function () use ($fixtures) {
	Assert::exception(fn() => ConfigLoader::loadFile("$fixtures/none.php"), ConfigurationException::class, 'Configuration file %a%none.php does not exist.');
	Assert::exception(fn() => ConfigLoader::loadFile("$fixtures/bad.php"), ConfigurationException::class, 'Configuration file %a%bad.php must return DressCode\Config.');
	Assert::exception(
		fn() => ConfigLoader::loadFile(FileMock::create('', 'txt')),
		ConfigurationException::class,
		'Configuration file %a% must be a .neon or a .php file.',
	);
});


test('a NEON file and a PHP file say the same thing, and the local file wins over the template', function () use ($fixtures) {
	Assert::same("$fixtures/formats/dresscode.neon", ConfigLoader::find("$fixtures/formats"));
	Assert::equal(
		ConfigLoader::loadFile("$fixtures/formats/dresscode.php.dist"),
		ConfigLoader::loadFile("$fixtures/formats/dresscode.neon"),
	);
});


test('the two formats side by side are an ambiguity, not a preference', function () {
	$dir = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/dresscode-formats';
	@mkdir($dir, recursive: true); // @ - may exist
	Helpers::purge($dir);
	file_put_contents("$dir/dresscode.neon", '');
	file_put_contents("$dir/dresscode.php", '');
	Assert::exception(
		fn() => ConfigLoader::find($dir),
		ConfigurationException::class,
		"Both $dir/dresscode.neon and $dir/dresscode.php exist; keep one of them.",
	);

	unlink("$dir/dresscode.php");
	rename("$dir/dresscode.neon", "$dir/dresscode.neon.dist");
	Assert::same("$dir/dresscode.neon.dist", ConfigLoader::find($dir));
});


test('a misspelled key is an error of the file', function () {
	Assert::exception(
		fn() => ConfigLoader::loadFile(FileMock::create("path: [src]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file %a%: Unexpected item 'path', did you mean 'paths'?",
	);
	Assert::exception(
		fn() => ConfigLoader::loadFile(FileMock::create("paths: [src\n", 'neon')),
		ConfigurationException::class,
		'Configuration file %a% is not valid NEON: %a%',
	);
	Assert::exception(
		fn() => ConfigLoader::loadFile(FileMock::create("phpVersion: 8.2\n", 'neon')),
		ConfigurationException::class,
		"Configuration file %a%: The item 'phpVersion' expects to be string, %a% given.",
	);
	Assert::exception(
		fn() => ConfigLoader::loadFile(FileMock::create("analyses: [Acme\\Nope]\n", 'neon')),
		ConfigurationException::class,
		'Analysis class Acme\Nope does not exist.',
	);
});
