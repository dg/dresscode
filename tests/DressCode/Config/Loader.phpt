<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\Loader;
use DressCode\ConfigurationException;
use DressCode\Presets\Per;
use Tester\Assert;
use Tester\FileMock;
use Tester\Helpers;


require __DIR__ . '/../../bootstrap.php';

$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


test('the file is searched upwards from the directory', function () use ($fixtures) {
	Assert::same("$fixtures/project/dresscode.php", Loader::find("$fixtures/project/src/sub"));
	Assert::same("$fixtures/project/dresscode.php", Loader::find("$fixtures\\project\\"));
	Assert::null(Loader::find(sys_get_temp_dir()));
});


test('load: the found file and its directory as the root', function () use ($fixtures) {
	[$config, $root] = (new Loader)->load(null, "$fixtures/project/src/sub");
	Assert::same("$fixtures/project", $root);
	Assert::same(['test/a' => true], $config->getRules());
	Assert::same([], $config->getPresets());
	Assert::same(['src'], $config->getPaths());
});


test('load: an explicit file is taken wherever the run started', function () use ($fixtures) {
	[$config, $root, $file] = (new Loader)->load("$fixtures/project/dresscode.php", sys_get_temp_dir());
	Assert::same("$fixtures/project", $root);
	Assert::same("$fixtures/project/dresscode.php", $file);
	Assert::same(['test/a' => true], $config->getRules());
});


test('load: without a file the default applies, resolved, and the directory is the root', function () {
	$dir = sys_get_temp_dir();
	[$config, $root, $file] = (new Loader)->load(null, $dir);
	Assert::same([Per::class], $config->getPresets());
	Assert::same(rtrim(str_replace('\\', '/', $dir), '/'), $root);
	Assert::null($file);

	$default = Config::create()->extension(fn(Config $config) => $config->preset('from/extension'));
	[$config] = (new Loader)->load(null, $dir, $default);
	Assert::same(['from/extension'], $config->getPresets());
	Assert::same([], $default->getPresets()); // the default itself is left untouched
});


test('errors', function () use ($fixtures) {
	Assert::exception(fn() => Loader::loadFile("$fixtures/none.php"), ConfigurationException::class, 'Configuration file %a%none.php does not exist.');
	Assert::exception(fn() => Loader::loadFile("$fixtures/bad.php"), ConfigurationException::class, 'Configuration file %a%bad.php must return DressCode\Config.');
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create('', 'txt')),
		ConfigurationException::class,
		'Configuration file %a% must be a .neon or a .php file.',
	);
});


test('a NEON file and a PHP file say the same thing, and the local file wins over the template', function () use ($fixtures) {
	Assert::same("$fixtures/formats/dresscode.neon", Loader::find("$fixtures/formats"));
	Assert::equal(
		Loader::loadFile("$fixtures/formats/dresscode.php.dist"),
		Loader::loadFile("$fixtures/formats/dresscode.neon"),
	);
});


test('the two formats side by side are an ambiguity, not a preference', function () {
	$dir = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/dresscode-formats';
	@mkdir($dir, recursive: true); // @ - may exist
	Helpers::purge($dir);
	file_put_contents("$dir/dresscode.neon", '');
	file_put_contents("$dir/dresscode.php", '');
	Assert::exception(
		fn() => Loader::find($dir),
		ConfigurationException::class,
		"Both $dir/dresscode.neon and $dir/dresscode.php exist; keep one of them.",
	);

	unlink("$dir/dresscode.php");
	rename("$dir/dresscode.neon", "$dir/dresscode.neon.dist");
	Assert::same("$dir/dresscode.neon.dist", Loader::find($dir));
});


test('a misspelled key is an error of the file', function () {
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("path: [src]\n", 'neon')),
		ConfigurationException::class,
		"Configuration file %a%: Unexpected item 'path', did you mean 'paths'?",
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("paths: [src\n", 'neon')),
		ConfigurationException::class,
		'Configuration file %a% is not valid NEON: %a%',
	);
	Assert::exception(
		fn() => Loader::loadFile(FileMock::create("analyses: [Acme\\Nope]\n", 'neon')),
		ConfigurationException::class,
		'Analysis class Acme\Nope does not exist.',
	);
});


test('the version of PHP is taken with quotes or without them', function () {
	$php = fn(string $file) => Loader::loadFile(FileMock::create($file, 'neon'))->getPhp();
	Assert::same('8.2', $php("php: '8.2'\n"));
	Assert::same('8.2', $php("php: 8.2\n"));
	Assert::same('8.2', $php("php: 8.2  # what composer.json says\n"));
	Assert::same('auto', $php("php: auto\n"));

	// a number is a version whose minor is a single digit, which every PHP ever released has had
	Assert::same('8.0', $php("php: 8.0\n"));
	Assert::same('8.0', $php("php: 8\n"));

	// a value that is no version at all is an error of the file, with the file named
	Assert::exception(
		fn() => $php("php: yes\n"),
		ConfigurationException::class,
		"Configuration file %a%: The item 'php' expects to be string|int|float, true given.",
	);
	Assert::exception(
		fn() => $php("php: '8'\n"),
		ConfigurationException::class,
		"Configuration file %a%: Invalid PHP version '8'.",
	);
	Assert::same('8.2', $php("php: 8.25\n")); // no such version, and the minor is one digit
});
