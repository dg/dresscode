<?php declare(strict_types=1);

use DressCode\Config;

return Config::create()
	->preset(Acme\DressCode\Presets\Acme::class)
	->paths(['src']);
