<?php declare(strict_types=1);

use DressCode\Config;

return new Config(
	extensions: [Acme\DressCode\Extension::class],
	presets: ['acme/default'],
	paths: ['src'],
);
