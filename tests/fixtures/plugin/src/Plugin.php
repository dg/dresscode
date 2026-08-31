<?php declare(strict_types=1);

namespace Acme\DressCode;

use DressCode\PluginManifest;


final class Plugin implements \DressCode\Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [Rules\NoVarDumpRule::class],
			presets: [Presets\Acme::class],
			excludePaths: ['generated'],
			ruleUrl: 'https://acme.dev/dresscode/{slug}',
		);
	}
}
