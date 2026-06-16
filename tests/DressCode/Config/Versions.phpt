<?php declare(strict_types=1);

use DressCode\Config\Versions;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the lowest version a constraint allows', function () {
	Assert::same('3.1', Versions::findLowestVersion('^3.1 || ^4.0'));
	Assert::same('8.0', Versions::findLowestVersion('^8.4 || ^8.0')); // the lowest of the alternatives, not the first
	Assert::same('7.4', Versions::findLowestVersion('~7.4.0|~8.0.0'));
	Assert::same('8.0', Versions::findLowestVersion('^8'));
	Assert::same('8.3', Versions::findLowestVersion('8.3.*|8.4.*'));
	Assert::same('5.6', Versions::findLowestVersion('>5.6'));
	Assert::same('8.2', Versions::findLowestVersion('8.2.*'));
	Assert::same('8.2', Versions::findLowestVersion('8.2'));
	Assert::same('8.1', Versions::findLowestVersion('>= 8.1'));
	Assert::same('8.0', Versions::findLowestVersion('>8.0')); // 8.0.1 is allowed
	Assert::same('8.0', Versions::findLowestVersion('!=8.1 >=8.0'));
	Assert::same('8.0.2', Versions::findLowestVersion('>=8.0.2, <8.0.15'));
	Assert::same('1.4.9999999.9999999', Versions::findLowestVersion('1.4.x-dev'));
	Assert::null(Versions::findLowestVersion('<8.4'));
	Assert::null(Versions::findLowestVersion('*'));
	Assert::null(Versions::findLowestVersion('dev-master'));
	Assert::null(Versions::findLowestVersion('not a constraint'));
});
