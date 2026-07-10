<?php declare(strict_types=1);

/**
 * Data sets for rules.phpt: the slug of every built-in rule and of every fixture directory. A rule without
 * fixtures and fixtures without a rule both become a data set that fails.
 */

use DressCode\Config\RuleRegistry;

require_once __DIR__ . '/../../../vendor/autoload.php'; // the runner loads this file outside the tests

$data = [];
foreach ((new RuleRegistry)->getRules() as $name => $class) {
	$slug = substr($name, strpos($name, '/') + 1);
	$data[$slug] = [$slug, $class];
}

foreach (glob(__DIR__ . '/fixtures/*', GLOB_ONLYDIR) ?: [] as $dir) {
	$data[basename($dir)] ??= [basename($dir), null];
}

ksort($data);
return $data;
