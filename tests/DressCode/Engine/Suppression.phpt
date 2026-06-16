<?php declare(strict_types=1);

use DressCode\Engine\Suppression;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$resolve = fn(string $name) => str_starts_with($name, 'dresscode/') ? [$name] : [];


/** @param Closure(string): list<string> $resolve */
function suppression(string $code, Closure $resolve): Suppression
{
	return Suppression::fromFile((new Parser)->parse($code), $resolve);
}


test('ignore on the same line and on its own line', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		$a; // dresscode:ignore dresscode/a
		$b; // dresscode:ignore
		// dresscode:ignore dresscode/a, dresscode/b
		foo(
			1,
		);
		$c;
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 2));
	Assert::false($s->isSilenced('dresscode/b', 2));
	Assert::true($s->isSilenced('dresscode/anything', 3));
	Assert::true($s->isSilenced('dresscode/a', 5));
	Assert::true($s->isSilenced('dresscode/b', 7));
	Assert::false($s->isSilenced('dresscode/c', 6));
	Assert::false($s->isSilenced('dresscode/a', 8));
	Assert::false($s->isSilenced('dresscode/a', 4));
});


test('what follows two dashes says why and names no rule', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		$a; // dresscode:ignore dresscode/a -- the loop is faster here
		$b; // dresscode:ignore -- for all of them
		$c; /* dresscode:ignore dresscode/c, dresscode/d -- kept for the reader */
		$e; // dresscode:ignore dresscode/e --
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 2));
	Assert::false($s->isSilenced('dresscode/b', 2));
	Assert::true($s->isSilenced('dresscode/anything', 3));
	Assert::true($s->isSilenced('dresscode/d', 4));
	Assert::false($s->isSilenced('dresscode/x', 4));
	Assert::true($s->isSilenced('dresscode/e', 5));
	Assert::false($s->isSilenced('dresscode/x', 5));
});


test('ignore on its own line above the first item of a list covers that item, not those after it', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		// dresscode:ignore dresscode/a
		$a;
		$b;
		function f()
		{
			// dresscode:ignore dresscode/a
			$c;
			$d;
		}
		foo(
			// dresscode:ignore dresscode/a
			$e,
			$f,
		);
		$g = [
			// dresscode:ignore dresscode/a
			1,
			2,
		];
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 3));
	Assert::false($s->isSilenced('dresscode/a', 4));
	Assert::true($s->isSilenced('dresscode/a', 8));
	Assert::false($s->isSilenced('dresscode/a', 9));
	Assert::true($s->isSilenced('dresscode/a', 13));
	Assert::false($s->isSilenced('dresscode/a', 14));
	Assert::true($s->isSilenced('dresscode/a', 18));
	Assert::false($s->isSilenced('dresscode/a', 19));
});


test('ignore on its own line above a line no node starts at covers the node starting later on it', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		if ($a) {
			x();
		// dresscode:ignore dresscode/a
		} else {
			y();
		}
		$b;
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 5));
	Assert::true($s->isSilenced('dresscode/a', 7));
	Assert::false($s->isSilenced('dresscode/a', 8));
});


test('ignore in a comment of several lines covers what follows its last line', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		/**
		 * dresscode:ignore dresscode/a
		 */
		function f() {
		}
		/*
		 * dresscode:ignore dresscode/b
		 */

		$a;
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 5));
	Assert::true($s->isSilenced('dresscode/a', 6));
	Assert::false($s->isSilenced('dresscode/a', 7));
	Assert::true($s->isSilenced('dresscode/b', 10));
	Assert::false($s->isSilenced('dresscode/b', 11));
});


test('the class of a rule is a name, and an unknown name is quoted without the end of its comment', function () {
	$resolve = fn(string $name) => $name === 'Acme\Rules\FooRule' ? [$name] : [];
	$s = suppression(<<<'XX'
		<?php
		$a; // dresscode:ignore Acme\Rules\FooRule
		$b; /* dresscode:ignore Acme\Rules\BarRule */
		$c; // dresscode:ignore \Acme\Rules\FooRule
		XX, $resolve);
	Assert::true($s->isSilenced('Acme\Rules\FooRule', 2));
	Assert::true($s->isSilenced('Acme\Rules\FooRule', 4));
	Assert::same(['Acme\Rules\BarRule' => 'dresscode:ignore Acme\Rules\BarRule'], $s->getUnknownNames());
});


test('disable and enable, also without a matching enable', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		$a;
		// dresscode:disable dresscode/a
		$b;
		/* dresscode:enable dresscode/a */
		$c;
		# dresscode:disable
		$d;
		XX, $resolve);
	Assert::false($s->isSilenced('dresscode/a', 2));
	Assert::true($s->isSilenced('dresscode/a', 4));
	Assert::false($s->isSilenced('dresscode/a', 6));
	Assert::true($s->isSilenced('dresscode/a', 8));
	Assert::true($s->isSilenced('dresscode/other', 8));
	Assert::false($s->isSilenced('dresscode/b', 4));

	$s = suppression(<<<'XX'
		<?php
		// dresscode:disable dresscode/a
		$a;
		// dresscode:disable dresscode/a
		$b;
		// dresscode:enable dresscode/a
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 3));
	Assert::true($s->isSilenced('dresscode/a', 5));
});


test('ignoreFile silences the whole file', function () use ($resolve) {
	$s = suppression("<?php\n// dresscode:ignoreFile\n\$a;", $resolve);
	Assert::true($s->isSilenced('dresscode/x', 3));
});


test('the hyphenated ignore-file is no directive', function () use ($resolve) {
	$s = suppression("<?php\n// dresscode:ignore-file\n\$a;", $resolve);
	Assert::false($s->isSilenced('dresscode/x', 3));
});
