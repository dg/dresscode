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
	Assert::true($s->isSuppressed('dresscode/a', 2));
	Assert::false($s->isSuppressed('dresscode/b', 2));
	Assert::true($s->isSuppressed('dresscode/anything', 3));
	Assert::true($s->isSuppressed('dresscode/a', 5));
	Assert::true($s->isSuppressed('dresscode/b', 7));
	Assert::false($s->isSuppressed('dresscode/c', 6));
	Assert::false($s->isSuppressed('dresscode/a', 8));
	Assert::false($s->isSuppressed('dresscode/a', 4));
});


test('what follows two dashes says why and names no rule', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		$a; // dresscode:ignore dresscode/a -- the loop is faster here
		$b; // dresscode:ignore -- for all of them
		$c; /* dresscode:ignore dresscode/c, dresscode/d -- kept for the reader */
		$e; // dresscode:ignore dresscode/e --
		XX, $resolve);
	Assert::true($s->isSuppressed('dresscode/a', 2));
	Assert::false($s->isSuppressed('dresscode/b', 2));
	Assert::true($s->isSuppressed('dresscode/anything', 3));
	Assert::true($s->isSuppressed('dresscode/d', 4));
	Assert::false($s->isSuppressed('dresscode/x', 4));
	Assert::true($s->isSuppressed('dresscode/e', 5));
	Assert::false($s->isSuppressed('dresscode/x', 5));
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
	Assert::true($s->isSuppressed('dresscode/a', 3));
	Assert::false($s->isSuppressed('dresscode/a', 4));
	Assert::true($s->isSuppressed('dresscode/a', 8));
	Assert::false($s->isSuppressed('dresscode/a', 9));
	Assert::true($s->isSuppressed('dresscode/a', 13));
	Assert::false($s->isSuppressed('dresscode/a', 14));
	Assert::true($s->isSuppressed('dresscode/a', 18));
	Assert::false($s->isSuppressed('dresscode/a', 19));
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
	Assert::false($s->isSuppressed('dresscode/a', 2));
	Assert::true($s->isSuppressed('dresscode/a', 4));
	Assert::false($s->isSuppressed('dresscode/a', 6));
	Assert::true($s->isSuppressed('dresscode/a', 8));
	Assert::true($s->isSuppressed('dresscode/other', 8));
	Assert::false($s->isSuppressed('dresscode/b', 4));
});


test('ignoreFile silences the whole file', function () use ($resolve) {
	$s = suppression("<?php\n// dresscode:ignoreFile\n\$a;", $resolve);
	Assert::true($s->isSuppressed('dresscode/x', 3));
});


test('the hyphenated ignore-file is no directive', function () use ($resolve) {
	$s = suppression("<?php\n// dresscode:ignore-file\n\$a;", $resolve);
	Assert::false($s->isSuppressed('dresscode/x', 3));
});
