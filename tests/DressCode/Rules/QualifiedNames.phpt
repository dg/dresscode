<?php declare(strict_types=1);

use DressCode\Rules\QualifiedNames;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the last segment of a name', function () {
	Assert::same('Order', QualifiedNames::stripNamespace('Acme\Shop\Order'));
	Assert::same('Order', QualifiedNames::stripNamespace('\Acme\Shop\Order'));
	Assert::same('Order', QualifiedNames::stripNamespace('Order'));
});


test('the namespace of a name', function () {
	Assert::same('Acme\Shop', QualifiedNames::extractNamespace('Acme\Shop\Order'));
	Assert::same('', QualifiedNames::extractNamespace('Order'));
	Assert::same('', QualifiedNames::extractNamespace('\Order'));
});
