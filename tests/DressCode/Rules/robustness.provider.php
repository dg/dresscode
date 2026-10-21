<?php declare(strict_types=1);

/**
 * Data sets for robustness.phpt: the standards, each with the sets a project uses beside them.
 */

return [
	'psr12' => ['presets' => ['psr12']],
	'perCs' => ['presets' => ['perCs', 'modernizations', 'cleanup']],
	'nette' => ['presets' => ['nette', 'modernizations', 'deprecations']],
	'symfony' => ['presets' => ['symfony', 'correctness']],
];
