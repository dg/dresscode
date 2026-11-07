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
