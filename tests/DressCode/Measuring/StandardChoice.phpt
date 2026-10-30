<?php declare(strict_types=1);

use DressCode\Measuring\{Project, StandardChoice, StandardReason};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$choose = fn(array $residues, ?string $hint = null) => new ReflectionMethod(StandardChoice::class, 'choose')->invoke(
	null,
	$residues,
	$hint,
);


test('the fewest decisions win, a close call going to what the formatter names, then to perCs', function () use ($choose) {
	Assert::same(['symfony', StandardReason::Fewest, []], $choose(['symfony' => 9, 'psr12' => 21, 'perCs' => 36]));
	Assert::same(['nette', StandardReason::Fewest, []], $choose(['symfony' => 20, 'nette' => 21, 'perCs' => 40], 'nette'));
	Assert::same(['perCs', StandardReason::Fewest, ['symfony']], $choose(['symfony' => 20, 'perCs' => 21, 'nette' => 40]));
	Assert::same(['nette', StandardReason::Fewest, ['symfony']], $choose(['nette' => 20, 'symfony' => 21, 'perCs' => 40]));
});


test('psr12 yields to perCs, unless perCs departs half again as much or the formatter names psr12', function () use ($choose) {
	Assert::same(['perCs', StandardReason::Successor, []], $choose(['psr12' => 9, 'perCs' => 11, 'symfony' => 24]));
	Assert::same(['perCs', StandardReason::Successor, []], $choose(['psr12' => 20, 'perCs' => 28, 'symfony' => 33], 'perCs'));
	Assert::same(['psr12', StandardReason::Fewest, []], $choose(['psr12' => 22, 'symfony' => 25, 'perCs' => 36]));
	Assert::same(['psr12', StandardReason::Fewest, []], $choose(['psr12' => 29, 'symfony' => 34, 'perCs' => 40], 'psr12'));
	Assert::same(['symfony', StandardReason::Fewest, []], $choose(['psr12' => 20, 'symfony' => 21, 'perCs' => 28], 'symfony'));
});


test('laravel is checked only for a project that is or requires a package of Laravel, or that Laravel Pint formats', function () {
	$root = createTempDir('laravel');
	file_put_contents("$root/composer.json", '{"name": "acme/eshop", "require": {"nette/application": "^3.2"}}');
	$standards = array_merge(...StandardChoice::listCandidates(Project::detect($root), null));
	Assert::notContains('laravel', $standards);
	Assert::contains('nette', $standards);

	file_put_contents("$root/composer.json", '{"name": "acme/shop", "require": {"laravel/framework": "^12.0"}}');
	Assert::contains('laravel', array_merge(...StandardChoice::listCandidates(Project::detect($root), null)));

	file_put_contents("$root/composer.json", '{"name": "acme/eshop"}');
	file_put_contents("$root/pint.json", '{"preset": "empty"}');
	Assert::contains('laravel', array_merge(...StandardChoice::listCandidates(Project::detect($root), null)));
});
