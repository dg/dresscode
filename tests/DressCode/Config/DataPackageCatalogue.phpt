<?php declare(strict_types=1);

use DressCode\Config\DataPackageCatalogue;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('what Packagist said is remembered for a day, so the run asks it nothing', function () {
	$dir = createTempDir('data-catalogue');
	FileSystem::write("$dir/data-packages.json", '{"dresscode/upgrade-nette": ["nette/", "latte/latte"]}');
	touch("$dir/data-packages.json", $time = time() - 100);
	Assert::same(['dresscode/upgrade-nette' => ['nette/', 'latte/latte']], DataPackageCatalogue::load($dir));
	clearstatcache();
	Assert::same($time, filemtime("$dir/data-packages.json")); // asking Packagist would have written it again
});


test('offline, what is remembered is read however old it is', function () {
	$dir = createTempDir('data-catalogue-offline');
	FileSystem::write("$dir/data-packages.json", '{"dresscode/upgrade-nette": ["nette/"]}');
	touch("$dir/data-packages.json", $time = time() - 10 * 86_400);
	Assert::same(['dresscode/upgrade-nette' => ['nette/']], DataPackageCatalogue::load($dir, offline: true));
	clearstatcache();
	Assert::same($time, filemtime("$dir/data-packages.json"));
	Assert::same([], DataPackageCatalogue::load(createTempDir('data-catalogue-empty'), offline: true));
});
