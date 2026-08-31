<?php declare(strict_types=1);

namespace Acme\DressCode;

use DressCode\{Decision, DecisionKind, PluginManifest};
use DressCode\Domains\Names;


final class Plugin implements \DressCode\Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [Rules\NoVarDumpRule::class],
			presets: ['acme/default' => __DIR__ . '/Presets/acme.neon'],
			excludePaths: ['generated'],
			ruleUrl: 'https://acme.dev/dresscode/{slug}',
			section: 'acme',
			decisions: [new Decision('acme.debugFunctions', new Names, 'The debugging functions', kind: DecisionKind::Parameter, default: ['var_dump'])],
		);
	}
}
