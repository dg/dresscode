<?php declare(strict_types=1);

/**
 * The catalog of the parameters of the functions PHP declares, and the question a replacement of a call asks
 * of it: may an argument written for one parameter stand at the other one.
 */

use DressCode\Analyses\Parameter;
use DressCode\Analyses\PhpSignatures;
use DressCode\Analyses\PhpSymbols;
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
	Assert::same('mixed', $parametersOf('array_key_exists')[0]->type, 'an untyped parameter takes anything');

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


test('an argument written for one parameter standing at another', function () {
	$int = new Parameter('offset', 'int');
	$nullableInt = new Parameter('length', '?int');
	$string = new Parameter('encoding', 'string|null');
	$mixed = new Parameter('value', 'mixed');
	$float = new Parameter('num', 'float');

	Assert::true($int->canReplace(new Parameter('start', 'int')), 'the same type under another name');
	Assert::true($nullableInt->canReplace($int), 'a wider type');
	Assert::false($int->canReplace($nullableInt), 'a narrower one');
	Assert::false($string->canReplace($int), 'an unrelated one');
	Assert::true($mixed->canReplace($string), 'mixed takes everything');
	Assert::false($string->canReplace($mixed));
	Assert::true($float->canReplace($int), 'PHP passes an int where a float is declared');

	Assert::false($int->canReplace(new Parameter('offset', 'int', byReference: true)), 'the argument is taken another way');
	Assert::true(new Parameter('matches', 'mixed', byReference: true)->canReplace(new Parameter('m', 'array', byReference: true)));
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
