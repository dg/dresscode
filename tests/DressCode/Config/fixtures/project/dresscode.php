<?php declare(strict_types=1);

use DressCode\Config;

return Config::create()
	->enable('test/a')
	->paths(['src']);
