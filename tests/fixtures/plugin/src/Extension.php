<?php declare(strict_types=1);

namespace Acme\DressCode;

use DressCode\Config;


final class Extension
{
	public function __invoke(Config $config): void
	{
		$config
			->registerRules([Rules\NoVarDumpRule::class])
			->registerPresets([Presets\Acme::class])
			->preset('acme/default')
			->excludePaths(['generated']);
	}
}
