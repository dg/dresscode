<?php declare(strict_types=1);

use DressCode\Config;

return new Config(
	use: ['acme/default', Acme\DressCode\Plugin::class],
	paths: ['src'],
);
