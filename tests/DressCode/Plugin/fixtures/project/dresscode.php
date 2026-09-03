<?php declare(strict_types=1);

use DressCode\Config;

return Config::create()
	->extension(Acme\DressCode\Extension::class)
	->paths(['src']);
