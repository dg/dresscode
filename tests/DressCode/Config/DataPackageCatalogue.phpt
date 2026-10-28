<?php declare(strict_types=1);

use DressCode\Config\DataPackageCatalogue;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('what Packagist said is remembered for a day, so the run asks it nothing', function () {
	$dir = createTempDir('data-catalogue');
	FileSystem::write("$dir/data-packages.json", '{"dresscode/upgrade-nette": ["nette/", "latte/latte"]}');
	Assert::same(['dresscode/upgrade-nette' => ['nette/', 'latte/latte']], DataPackageCatalogue::load($dir));
});
