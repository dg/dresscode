<?php declare(strict_types=1);

use DressCode\Engine\Diff;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('identical texts give nothing', function () {
	Assert::same('', Diff::unified("a\nb\n", "a\nb\n", 'f.php'));
});


test('changes with context', function () {
	$old = implode("\n", range(1, 12)) . "\n";
	$new = str_replace(["5\n", "10\n"], ["five\n", "10\nten and a half\n"], $old);
	Assert::match(<<<'XX'
		--- f.php
		+++ f.php
		@@ -2,11 +2,12 @@
		 2
		 3
		 4
		-5
		+five
		 6
		 7
		 8
		 9
		 10
		+ten and a half
		 11
		 12

		XX, Diff::unified($old, $new, 'f.php'));
});


test('no newline at end of file', function () {
	Assert::match(<<<'XX'
		--- f.php
		+++ f.php
		@@ -1,1 +1,1 @@
		-a
		\ No newline at end of file
		+a

		XX, Diff::unified('a', "a\n", 'f.php'));
});
