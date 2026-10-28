<?php declare(strict_types=1);

use DressCode\Upgrade\{Blocker, Composer, Target};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the packages refusing a version are read from what why-not writes, the project itself left out', function () {
	$output = <<<'XX'
		acme/shop                     dev-master requires php (>= 8.2)
		latte/latte                   v3.1.6     requires php (8.2 - 8.5)
		nette/application             v3.3.0     requires php (8.3 - 8.5)
		acme/legacy                   v1.0.0     conflicts php (>=8.4)
		Not finding what you were looking for? Try calling `composer require "php:8.6" --dry-run` to get another view on the problem.
		XX;

	$blockers = Composer::parseBlockers(Target::php('8.6'), $output, 'acme/shop');
	Assert::equal(Target::php('8.6'), $blockers->target);
	Assert::equal(
		[
			new Blocker('latte/latte', 'v3.1.6', '8.2 - 8.5'),
			new Blocker('nette/application', 'v3.3.0', '8.3 - 8.5'),
			new Blocker('acme/legacy', 'v1.0.0', 'conflicts with php >=8.4'),
		],
		$blockers->packages,
	);
});


test('nothing refuses a version where why-not says no installed package depends on it otherwise', function () {
	$output = 'There is no installed package depending on "php" in versions not matching 8.5';
	$blockers = Composer::parseBlockers(Target::php('8.5'), $output, null);
	Assert::same([], $blockers->packages);
	Assert::null($blockers->unknown);
});
