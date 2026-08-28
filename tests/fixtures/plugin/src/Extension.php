<?php declare(strict_types=1);

namespace Acme\DressCode;

use DressCode\Config;


final class Extension implements \DressCode\Extension
{
	public function getConfig(): Config
	{
		return new Config(
			extensions: [Rules\NoVarDumpRule::class, Presets\Acme::class],
			excludePaths: ['generated'],
		);
	}
}
