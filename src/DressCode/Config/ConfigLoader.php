<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Presets;


/**
 * Finds and loads dresscode.php; without a file the caller's default applies, or the default preset.
 */
final class ConfigLoader
{
	public const FileName = 'dresscode.php';


	/**
	 * @param  ?string  $file  the configuration file, or null to search from the directory upwards
	 * @param  ?Config  $default  what applies when there is no configuration file; the default preset when null
	 * @return array{Config, string, ?string}  the configuration, the root directory with slashes, and the file it came from
	 * @throws ConfigurationException
	 */
	public function load(?string $file, string $directory, ?Config $default = null): array
	{
		$file ??= self::find($directory);
		if ($file === null) {
			$config = $default ?? Config::create()->preset(Presets\Per::class);
			$root = $directory;
		} else {
			$config = self::loadFile($file);
			$root = dirname($file);
		}

		$config = $config->resolveExtensions();
		$root = realpath($root) ?: $root;
		return [$config, rtrim(str_replace('\\', '/', $root), '/'), $file];
	}


	/**
	 * The nearest dresscode.php in the directory or above it.
	 */
	public static function find(string $directory): ?string
	{
		$directory = rtrim(str_replace('\\', '/', $directory), '/');
		while (true) {
			$file = "$directory/" . self::FileName;
			if (is_file($file)) {
				return $file;
			}

			$parent = dirname($directory);
			if ($parent === $directory) {
				return null;
			}

			$directory = $parent;
		}
	}


	/** @throws ConfigurationException */
	public static function loadFile(string $file): Config
	{
		if (!is_file($file)) {
			throw new ConfigurationException("Configuration file $file does not exist.");
		}

		$config = require $file;
		if (!$config instanceof Config) {
			throw new ConfigurationException("Configuration file $file must return DressCode\\Config.");
		}

		return $config;
	}
}
