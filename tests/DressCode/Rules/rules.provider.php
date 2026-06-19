<?php declare(strict_types=1);

/**
 * Data sets for rules.phpt: the slug of every built-in rule and of every directory of fixtures. A rule
 * without fixtures and fixtures without a rule both become a data set that fails.
 */

use DressCode\Config\PluginRegistry;

require_once __DIR__ . '/../../../vendor/autoload.php'; // the runner loads this file outside the tests

$data = [];
foreach ((new PluginRegistry)->rules as $class) {
	$slug = lcfirst(substr($class, strrpos($class, '\\') + 1, -strlen('Rule')));
	$data[$slug] = [$slug, $class];
}

foreach (glob(__DIR__ . '/fixtures/*', GLOB_ONLYDIR) ?: [] as $dir) {
	$data[basename($dir)] ??= [basename($dir), null];
}

ksort($data);
return $data;
