<?php declare(strict_types=1);

/**
 * The messages the fixtures record keep the shapes of their families: a `Useless…Rule` says why after `because`, or
 * after `but` where it leaves the occurrence as it is.
 */

use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


foreach (require __DIR__ . '/rules.provider.php' as [$slug, $class]) {
	if ($class === null || !str_starts_with(substr($class, strrpos($class, '\\') + 1), 'Useless')) {
		continue;
	}

	foreach (glob(__DIR__ . "/fixtures/$slug/*.violations") ?: [] as $file) {
		foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			if (preg_match('~^\d+: (.+)~', $line, $m)) {
				Assert::match('~^Useless .+, (because|but) .+\.$~', $m[1], "$slug/" . basename($file));
			}
		}
	}
}
