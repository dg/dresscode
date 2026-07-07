<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Presets;


/**
 * Finds and loads dresscode.php with its extra layers; without a file the default preset applies.
 */
final class ConfigLoader
{
	public const FileName = 'dresscode.php';


	/**
	 * @param  ?string  $file  the configuration file, or null to search from the directory upwards
	 * @param  bool  $defaultPreset  use the default preset when there is no configuration file
	 * @return array{Config, string}  the configuration and the root directory, with slashes
	 * @throws ConfigurationException
	 */
	public function load(?string $file, string $directory, bool $defaultPreset = true): array
	{
		$file ??= self::find($directory);
		if ($file === null) {
			$config = Config::create();
			if ($defaultPreset) {
				$config->preset(Presets\Per::class);
			}

			$root = $directory;
		} else {
			$config = self::loadFile($file);
			$root = dirname($file);
		}

		$root = realpath($root) ?: $root;
		return [$config, rtrim(str_replace('\\', '/', $root), '/')];
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
