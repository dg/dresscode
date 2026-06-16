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


test('the edit script is a shortest one', function () {
	mt_srand(42);
	$edits = new ReflectionMethod(Diff::class, 'computeEdits')->getClosure();
	for ($round = 0; $round < 300; $round++) {
		$a = array_map(fn() => (string) mt_rand(1, 4), range(1, mt_rand(0, 12)));
		$b = array_map(fn() => (string) mt_rand(1, 4), range(1, mt_rand(0, 12)));
		$script = $edits($a, $b);
		$lengths = array_fill(0, count($a) + 1, array_fill(0, count($b) + 1, 0));
		for ($i = count($a) - 1; $i >= 0; $i--) {
			for ($j = count($b) - 1; $j >= 0; $j--) {
				$lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
			}
		}

		Assert::same($a, array_column(array_filter($script, fn($edit) => $edit[0] !== '+'), 1));
		Assert::same($b, array_column(array_filter($script, fn($edit) => $edit[0] !== '-'), 1));
		Assert::same($lengths[0][0], count(array_filter($script, fn($edit) => $edit[0] === ' ')));
	}
});


test('a file indented anew stays cheap', function () {
	$old = str_repeat("if (\$a) {\nfoo(\$b);\n}\n", 3000);
	$new = str_replace(['foo', "\n}"], ["\tfoo", "\n }"], $old);
	$memory = memory_get_usage();
	Diff::unified($old, $new, 'f.php');
	Assert::true(memory_get_peak_usage() - $memory < 20_000_000);
});
