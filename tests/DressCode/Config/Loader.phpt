<?php declare(strict_types=1);

use DressCode\Config\Loader;
use DressCode\ConfigurationException;
use DressCode\Presets\Per;
use Tester\Assert;


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


test('load: no file means the default preset and the directory as the root', function () {
	$dir = sys_get_temp_dir();
	[$config, $root] = (new Loader)->load(null, $dir);
	Assert::same([Per::class], $config->getPresets());
	Assert::same(rtrim(str_replace('\\', '/', $dir), '/'), $root);
	[$config] = (new Loader)->load(null, $dir, defaultPreset: false);
	Assert::same([], $config->getPresets());
});


test('errors', function () use ($fixtures) {
	Assert::exception(fn() => Loader::loadFile("$fixtures/none.php"), ConfigurationException::class, 'Configuration file %a%none.php does not exist.');
	Assert::exception(fn() => Loader::loadFile("$fixtures/bad.php"), ConfigurationException::class, 'Configuration file %a%bad.php must return DressCode\Config.');
});
