<?php declare(strict_types=1);

/**
 * The fixtures and the examples of every rule, one process per rule.
 * @dataProvider rules.provider.php
 */

use DressCode\Testing\RuleTester;
use Tester\{Assert, Environment};

require __DIR__ . '/../../bootstrap.php';

$arg = $_SERVER['argv'][1] ?? ''; // php rules.phpt <slug> runs a single rule
[$slug, $class] = str_starts_with($arg, '-') || $arg === ''
	? Environment::loadData()
	: (require __DIR__ . '/rules.provider.php')[$arg] ?? [$arg, null];

if ($class === null) {
	Assert::fail("The fixtures in fixtures/$slug or examples/$slug belong to no rule.");
}

$examples = __DIR__ . "/../../../examples/$slug";
foreach ([__DIR__ . "/fixtures/$slug", $examples] as $dir) {
	foreach (glob("$dir/*.code") ?: [] as $file) {
		// what the rule says and where is part of its contract, so every fixture records it
		Assert::true(is_file(preg_replace('~\.code$~', '.violations', $file)), basename($file) . ' has no .violations file.');
	}
}

Assert::noError(fn() => RuleTester::run($class, __DIR__ . '/fixtures/' . $slug));
if (is_dir($examples)) {
	Assert::noError(fn() => RuleTester::run($class, $examples));
}
