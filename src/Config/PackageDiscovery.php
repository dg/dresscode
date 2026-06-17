<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Plugin;
use function array_key_exists, is_string;


/**
 * What a package declares for DressCode under `extra.dresscode` of its composer.json: the plugin of its rules.
 * @internal
 */
final readonly class PackageDiscovery
{
	/**
	 * The plugin the package of the class names in `extra.dresscode.plugin`, the package being the nearest composer.json
	 * above the file of the class, as a test of a rule of a plugin finds the decisions its manifest declares; null where
	 * it names none.
	 * @param  class-string  $class
	 */
	public static function findPluginOf(string $class): ?Plugin
	{
		static $plugins = [];
		$file = new \ReflectionClass($class)->getFileName();
		$composerFile = $file === false ? null : Composer::findFile(dirname($file));
		if ($composerFile === null) {
			return null;
		} elseif (!array_key_exists($composerFile, $plugins)) {
			$plugin = Composer::read($composerFile)['extra']['dresscode']['plugin'] ?? null;
			$plugins[$composerFile] = is_string($plugin) && is_subclass_of($plugin, Plugin::class) ? new $plugin : null;
		}

		return $plugins[$composerFile];
	}
}
