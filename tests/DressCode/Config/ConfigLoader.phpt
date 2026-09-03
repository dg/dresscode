<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\ConfigLoader;
use DressCode\ConfigurationException;
use DressCode\Presets\Per;
use Tester\Assert;

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
});
