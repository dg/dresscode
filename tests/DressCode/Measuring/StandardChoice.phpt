<?php declare(strict_types=1);

use DressCode\Measuring\StandardChoice;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$choose = fn(array $residues, array $hints = [null, null]) => new ReflectionMethod(StandardChoice::class, 'choose')->invoke(
	null,
	$residues,
	$hints,
);


test('the fewest decisions win, a close call going to the hints, then to perCs', function () use ($choose) {
	Assert::same('symfony', $choose(['symfony' => 9, 'psr12' => 21, 'perCs' => 36]));
	Assert::same('nette', $choose(['symfony' => 20, 'nette' => 21, 'perCs' => 40], ['nette', null]));
	Assert::same('perCs', $choose(['symfony' => 20, 'perCs' => 21, 'nette' => 40]));
});
