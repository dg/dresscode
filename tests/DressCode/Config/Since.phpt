<?php declare(strict_types=1);

use DressCode\Config\Since;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('one version holds from it on', function () {
	$since = Since::parse('3.1.5');
	Assert::false($since->holdsFor('3.0.30'));
	Assert::false($since->holdsFor('3.1.4'));
	Assert::true($since->holdsFor('3.1.5'));
	Assert::true($since->holdsFor('3.2'));
	Assert::true($since->holdsFor('4'));
});


test('a version for each series holds in a series from its version, above them all from the start', function () {
	$since = Since::parse('3.0.26 3.1.2');
	Assert::false($since->holdsFor('3.0'));
	Assert::false($since->holdsFor('3.0.25'));
	Assert::true($since->holdsFor('3.0.26'));
	Assert::false($since->holdsFor('3.1'));
	Assert::false($since->holdsFor('3.1.1'));
	Assert::true($since->holdsFor('3.1.2'));
	Assert::true($since->holdsFor('3.2'));
	Assert::false($since->holdsFor('2.9.9'));

	// a series between them never got the entries
	$since = Since::parse('3.8.9 3.10.1');
	Assert::false($since->holdsFor('3.9.5'));
	Assert::true($since->holdsFor('3.10.1'));
	Assert::true($since->holdsFor('3.11'));
});


test('the version the line of a version has the entries from, and the one that brings them', function () {
	$since = Since::parse('3.0.26 3.1.2');
	Assert::same('3.0.26', $since->findVersionFor(null));
	Assert::same('3.0.26', $since->findVersionFor('3.0.30'));
	Assert::same('3.1.2', $since->findVersionFor('3.1.5'));
	Assert::same('3.1.2', $since->findVersionFor('3.2'));

	Assert::same('3.0.26', $since->findVersionAfter(null));
	Assert::same('3.0.26', $since->findVersionAfter('3.0.10'));
	Assert::same('3.1.2', $since->findVersionAfter('3.1'));
	Assert::same('3.0.26', $since->getOldest());
	Assert::same('3.1.2', $since->getNewest());
});


test('a series named by its start had the entries before the version below it', function () {
	$since = Since::parse('3.0.25 3.1');
	Assert::false($since->holdsFor('3.0.24'));
	Assert::true($since->holdsFor('3.0.25'));
	Assert::true($since->holdsFor('3.1'));
	Assert::same('3.1', $since->findVersionFor('3.1.4'));
	Assert::same(['3.2.10', '4'], Since::parse('3.2.10 4')->versions);
});


test('the versions are of different series, the oldest first', function () {
	Assert::exception(fn() => Since::parse('3.0.26, 3.1.2'), InvalidArgumentException::class, '`3.0.26,` is not a version%a%');
	Assert::exception(
		fn() => Since::parse('3.1.2 3.0.26'),
		InvalidArgumentException::class,
		'The versions `3.1.2 3.0.26` must be each of another series, the oldest first.',
	);
	Assert::exception(
		fn() => Since::parse('3.1.2 3.1.5'),
		InvalidArgumentException::class,
		'The versions `3.1.2 3.1.5` must be each of another series, the oldest first.',
	);
	Assert::same(['3.1', '4.0.5'], Since::parse('3.1 4.0.5')->versions);
});
