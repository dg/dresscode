<?php declare(strict_types=1);

use DressCode\Measuring\{StandardChoice, StandardReason};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$choose = fn(array $residues, array $hints = [null, null]) => new ReflectionMethod(StandardChoice::class, 'choose')->invoke(
	null,
	$residues,
	$hints,
);


test('the fewest decisions win, a close call going to the hints, then to perCs', function () use ($choose) {
	Assert::same(['symfony', StandardReason::Fewest], $choose(['symfony' => 9, 'psr12' => 21, 'perCs' => 36]));
	Assert::same(['nette', StandardReason::Fewest], $choose(['symfony' => 20, 'nette' => 21, 'perCs' => 40], ['nette', null]));
	Assert::same(['perCs', StandardReason::Fewest], $choose(['symfony' => 20, 'perCs' => 21, 'nette' => 40]));
});


test('psr12 yields to perCs, its successor, unless perCs departs half again as much or a hint names psr12', function () use ($choose) {
	Assert::same(['perCs', StandardReason::Successor], $choose(['psr12' => 9, 'perCs' => 11, 'symfony' => 24]));
	Assert::same(['perCs', StandardReason::Successor], $choose(['psr12' => 20, 'perCs' => 28, 'symfony' => 33], [null, 'perCs']));
	Assert::same(['psr12', StandardReason::Fewest], $choose(['psr12' => 22, 'symfony' => 25, 'perCs' => 36]));
	Assert::same(['psr12', StandardReason::Fewest], $choose(['psr12' => 29, 'symfony' => 34, 'perCs' => 40], [null, 'psr12']));
	Assert::same(['symfony', StandardReason::Fewest], $choose(['psr12' => 20, 'symfony' => 21, 'perCs' => 28], [null, 'symfony']));
});
