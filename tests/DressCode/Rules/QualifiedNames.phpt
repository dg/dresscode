<?php declare(strict_types=1);

use DressCode\Rules\QualifiedNames;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the last segment of a name', function () {
	Assert::same('Order', QualifiedNames::stripNamespace('Acme\Shop\Order'));
	Assert::same('Order', QualifiedNames::stripNamespace('\Acme\Shop\Order'));
	Assert::same('Order', QualifiedNames::stripNamespace('Order'));
});
