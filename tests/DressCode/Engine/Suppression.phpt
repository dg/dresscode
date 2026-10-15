<?php declare(strict_types=1);

use DressCode\Engine\Suppression;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$foreign = ['Generic.Files.LineLength' => ['dresscode/lineLength']];
$resolve = fn(string $name) => $foreign[$name] ?? (str_starts_with($name, 'dresscode/') ? [$name] : []);


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


test('a section or a structure silences every decision under it, and a name that stands for nothing is remembered', function () {
	$resolve = fn(string $name) => in_array($name, ['spacing', 'multiline.trailingComma'], true) ? [$name] : [];
	$s = suppression(<<<'XX'
		<?php
		$a; // dresscode:ignore spacing
		$b; // dresscode:ignore multiline.trailingComma, nope
		$c; // phpcs:ignore Some.Sniff
		XX, $resolve);
	Assert::true($s->isSilenced('spacing.call', 2));
	Assert::false($s->isSilenced('spacingOther.call', 2));
	Assert::true($s->isSilenced('multiline.trailingComma.array', 3));
	Assert::false($s->isSilenced('multiline.operatorPosition.binary', 3));
	Assert::same(['nope' => 'dresscode:ignore multiline.trailingComma, nope'], $s->getUnknownNames());
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


test('ignoreFile and phpcs forms with alias translation', function () use ($resolve) {
	$s = suppression("<?php\n// dresscode:ignoreFile\n\$a;", $resolve);
	Assert::true($s->isSilenced('dresscode/x', 3));

	$s = suppression(<<<'XX'
		<?php
		$a; // phpcs:ignore Generic.Files.LineLength
		// phpcs:disable Generic.Files.LineLength
		$b;
		// phpcs:enable
		/**
		 * @phpcsSuppress Generic.Files.LineLength
		 */
		function f() {
			$c;
		}
		$d;
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/lineLength', 2));
	Assert::true($s->isSilenced('dresscode/lineLength', 4));
	Assert::false($s->isSilenced('dresscode/lineLength', 5));
	Assert::true($s->isSilenced('dresscode/lineLength', 10));
	Assert::false($s->isSilenced('dresscode/lineLength', 12));

	$s = suppression("<?php\n// phpcs:ignoreFile\n\$a;", $resolve);
	Assert::true($s->isSilenced('dresscode/x', 3));
});


test('the hyphenated ignore-file is no directive', function () use ($resolve) {
	foreach (['dresscode', 'phpcs'] as $prefix) {
		$s = suppression("<?php\n// $prefix:ignore-file\n\$a;", $resolve);
		Assert::false($s->isSilenced('dresscode/x', 3));
	}
});


test('@phpcsSuppress with our names, several in one tag', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		/**
		 * @phpcsSuppress dresscode/a, dresscode/b
		 * @phpcsSuppress dresscode/c
		 */
		function f() {
			$c;
		}
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 7));
	Assert::true($s->isSilenced('dresscode/b', 7));
	Assert::true($s->isSilenced('dresscode/c', 7));
	Assert::false($s->isSilenced('dresscode/d', 7));
});


test('@phpcsSuppress on the first item of a list covers that item, not the list', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		/** @phpcsSuppress dresscode/a */
		function f() {
		}
		class A
		{
			/** @phpcsSuppress dresscode/b */
			public function g() {
			}
			public function h() {
			}
		}
		$a; /** @phpcsSuppress dresscode/c */ function i() {
		}
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 4));
	Assert::false($s->isSilenced('dresscode/a', 5));
	Assert::true($s->isSilenced('dresscode/b', 9));
	Assert::false($s->isSilenced('dresscode/b', 10));
	Assert::true($s->isSilenced('dresscode/c', 14));
	Assert::false($s->isSilenced('dresscode/c', 12));
});


test('@phpcsSuppress after a parameter covers the parameter', function () use ($resolve) {
	$s = suppression(<<<'XX'
		<?php
		function f(
			$a /** @phpcsSuppress dresscode/a */,
			$b,
		) {
		}
		XX, $resolve);
	Assert::true($s->isSilenced('dresscode/a', 3));
	Assert::false($s->isSilenced('dresscode/a', 4));
});


test('a comment the configuration names silences its rules on its line', function () use ($resolve) {
	$code = <<<'XX'
		<?php
		if ($a == null) { // intentionally ==, skip nulls
		}
		if (
			$b != null // intentionally ==
			&& $c
		) {}
		$d == 1; // a plain comment
		@mkdir($e); // @ may exist
		// intentionally ==
		$f == 1;
		$g == 1;
		XX;
	$s = Suppression::fromFile((new Parser)->parse($code), $resolve, $code, [
		'~\bintentionally\s+==~' => ['dresscode/noLooseComparisons'],
		'~^//\s*@\s+\S~' => ['dresscode/noErrorSuppression'],
	]);
	Assert::true($s->isSilenced('dresscode/noErrorSuppression', 9));
	Assert::false($s->isSilenced('dresscode/noErrorSuppression', 8));
	Assert::true($s->isSilenced('dresscode/noLooseComparisons', 2));
	Assert::false($s->isSilenced('dresscode/other', 2));
	Assert::true($s->isSilenced('dresscode/noLooseComparisons', 5));
	Assert::false($s->isSilenced('dresscode/noLooseComparisons', 6));
	Assert::false($s->isSilenced('dresscode/noLooseComparisons', 8));
	Assert::true($s->isSilenced('dresscode/noLooseComparisons', 11));
	Assert::false($s->isSilenced('dresscode/noLooseComparisons', 12));
});
