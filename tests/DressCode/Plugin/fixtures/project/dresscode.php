<?php declare(strict_types=1);

use DressCode\Config;

return new Config(
	plugins: [Acme\DressCode\Plugin::class],
	presets: ['acme/default'],
	paths: ['src'],
);
