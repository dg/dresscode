#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Writes the .violations file of every rule fixture that has none, so that the texts and the positions of the
 * reports are pinned by the tests. Review the diff before committing; a wrong message recorded here is a wrong
 * message tested everywhere.
 *
 * Usage: php tools/update-violations.php [--all] [slug...]
 * --all rewrites the existing files too, which is how a deliberate change of the texts is taken over.
 */

use DressCode\Testing\RuleTester;

require __DIR__ . '/../vendor/autoload.php';

$args = array_slice($_SERVER['argv'], 1);
$all = in_array('--all', $args, strict: true);
$slugs = array_values(array_filter($args, fn(string $arg) => !str_starts_with($arg, '-')));
$dir = __DIR__ . '/../tests/DressCode/Rules';

// in a closure: the provider itself uses variables and require would spill them here
$load = static fn(string $file): array => require $file;
/** @var array<string, array{string, ?class-string<DressCode\Rule>}> $provider */
$provider = $load("$dir/rules.provider.php");
$written = $skipped = 0;
foreach ($provider as [$slug, $class]) {
	if ($class === null || ($slugs && !in_array($slug, $slugs, strict: true))) {
		continue;
	}

	foreach (glob("$dir/fixtures/$slug/*.code") ?: [] as $file) {
		$target = (string) preg_replace('~\.code$~', '.violations', $file);
		if (!$all && is_file($target)) {
			$skipped++;
			continue;
		}

		$violations = RuleTester::collectViolations($class, $file);
		file_put_contents($target, $violations ? implode("\n", $violations) . "\n" : '');
		echo $violations ? '' : '(no violations) ';
		echo substr($file, strlen($dir) + 10), "\n";
		$written++;
	}
}

echo "$written files written, $skipped left alone\n";
