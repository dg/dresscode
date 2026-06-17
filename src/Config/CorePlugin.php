<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Plugin, PluginManifest};


/**
 * What the core of DressCode brings: its rules, whose pages are on dresscode.run.
 * @internal
 */
final class CorePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		// built once, since nothing of it changes
		static $manifest;
		return $manifest ??= new PluginManifest(
			rules: [],
		);
	}
}
