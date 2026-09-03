<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Presets;

use DressCode\{Preset, PresetInfo, Profile};


/**
 * What the container configurator of Symfony declares in its own namespace, where a project writes the files that
 * configure its services to call those functions without importing them. It knows a framework, not the project,
 * so it adds to the lists and leaves the resolution to the configuration; a name an older Symfony does not have yet
 * matters only to a file in that namespace.
 */
#[PresetInfo(
	'dresscode/symfony-configurator',
	'The functions the container configurator of Symfony declares in its namespace',
)]
final class SymfonyConfigurator implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(
			namespaces: [
				'functions' => [
					'Symfony\Component\DependencyInjection\Loader\Configurator\{abstract_arg, closure, env, expr, inline_service, iterator, lazy_proxy, param, service, service_closure, service_locator, tagged_class_map, tagged_iterator, tagged_locator}',
				],
			],
		);
	}
}
