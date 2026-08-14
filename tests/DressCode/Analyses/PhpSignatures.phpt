<?php declare(strict_types=1);

/**
 * The catalog of the parameters of the functions PHP declares.
 */

use DressCode\Analyses\{Parameter, PhpSignatures, PhpSymbols};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$signatures = new PhpSignatures;

/** @return list<Parameter> */
$parametersOf = fn(string $function): array => $signatures->findParameters($function)
	?? throw new Exception("PHP declares no $function().");


test('the parameters of a function, in their order', function () use ($parametersOf) {
	$names = fn(string $function) => array_map(fn(Parameter $p) => $p->name, $parametersOf($function));

	Assert::same(['string', 'offset', 'length'], $names('substr'));
	Assert::same(['string', 'start', 'length', 'encoding'], $names('mb_substr'));
	Assert::same(['haystack', 'needle', 'encoding'], $names('mb_substr_count'));
	Assert::same(['string', 'offset', 'length'], $names('SUBSTR'), 'the name is case insensitive');
});


test('the shapes a parameter comes in', function () use ($parametersOf) {
	[$string, $offset, $length] = $parametersOf('substr');
	Assert::same('string', $string->type);
	Assert::same('int|null', $length->type);
	Assert::false($offset->variadic);
	Assert::false($offset->byReference);

	Assert::true($parametersOf('preg_match')[2]->byReference);
	Assert::true($parametersOf('sprintf')[1]->variadic);
	Assert::null($parametersOf('array_key_exists')[0]->type, 'an untyped parameter');

	Assert::false($string->optional);
	Assert::true($length->optional);
	Assert::true($parametersOf('rand')[0]->optional, 'a function called with all of its arguments or none');
	Assert::false($parametersOf('date')[0]->optional);
});


test('a function without parameters and one the catalog does not hold', function () use ($signatures) {
	Assert::same([], $signatures->findParameters('time'));
	Assert::null($signatures->findParameters('apache_request_headers'), 'an extension the source cannot see');
	Assert::null($signatures->findParameters('myOwnFunction'));
});


test('the version of PHP that declares a function', function () {
	$symbols = new PhpSymbols;

	Assert::true($symbols->isInternalFunction('mb_trim'));
	Assert::false($symbols->isInternalFunction('mb_trim', '8.3'), 'PHP 8.4 added it');
	Assert::true($symbols->isInternalFunction('mb_trim', '8.4'));
	Assert::true($symbols->isInternalFunction('mb_trim', '8.6'), 'a target beyond the catalog keeps what the newest version has');

	Assert::true($symbols->isInternalFunction('imap_open', '8.3'));
	Assert::false($symbols->isInternalFunction('imap_open', '8.4'), 'PHP 8.4 dropped it');

	Assert::true($symbols->isInternalFunction('mb_substr', '8.0'), 'a function every version has');
	Assert::false($symbols->isInternalFunction('myOwnFunction', '8.0'));
});
