<?php declare(strict_types=1);

/**
 * The fixtures of every rule, one process per rule.
 * @dataProvider rules.provider.php
 */

use DressCode\Testing\RuleTester;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/../../bootstrap.php';

$arg = $_SERVER['argv'][1] ?? ''; // php rules.phpt <slug> runs a single rule
[$slug, $class] = str_starts_with($arg, '-') || $arg === ''
	? Environment::loadData()
	: (require __DIR__ . '/rules.provider.php')[$arg] ?? [$arg, null];

if ($class === null) {
	Assert::fail("The fixtures in fixtures/$slug belong to no rule.");
}

foreach (glob(__DIR__ . "/fixtures/$slug/*.code") ?: [] as $file) {
	// what the rule says and where is part of its contract, so every fixture records it
	Assert::true(is_file(preg_replace('~\.code$~', '.violations', $file)), basename($file) . ' has no .violations file; write it with php tools/update-violations.php');
}

Assert::noError(fn() => RuleTester::run($class, __DIR__ . '/fixtures/' . $slug));
