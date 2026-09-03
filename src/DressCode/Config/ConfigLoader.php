<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurationException;
use DressCode\Presets;


/**
 * Finds and loads the configuration file; without one the caller's default applies, or the default preset.
 */
final class ConfigLoader
{
	/** the two formats, in no order of preference: only one of them may lie in a directory */
	public const FileNames = ['dresscode.neon', 'dresscode.php'];

	/** the committed template a local file of the same format replaces whole */
	public const DistSuffix = '.dist';


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
	 * The nearest configuration file in the directory or above it: within a directory the local file wins
	 * over the .dist template, and the two formats are an ambiguity nobody can resolve for the user.
	 * @throws ConfigurationException
	 */
	public static function find(string $directory): ?string
	{
		$directory = rtrim(str_replace('\\', '/', $directory), '/');
		while (true) {
			foreach (['', self::DistSuffix] as $suffix) {
				$found = array_values(array_filter(
					array_map(fn(string $name) => "$directory/$name$suffix", self::FileNames),
					is_file(...),
				));
				if (count($found) > 1) {
					throw new ConfigurationException('Both ' . implode(' and ', $found) . ' exist; keep one of them.');
				} elseif ($found) {
					return $found[0];
				}
			}

			$parent = dirname($directory);
			if ($parent === $directory) {
				return null;
			}

			$directory = $parent;
		}
	}


	/** The format follows the extension of the file, a .dist template that of the file it stands for. */
	public static function loadFile(string $file): Config
	{
		if (!is_file($file)) {
			throw new ConfigurationException("Configuration file $file does not exist.");
		}

		$name = (string) preg_replace('~\\' . self::DistSuffix . '$~', '', $file);
		return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
			'neon' => NeonReader::read($file),
			'php' => self::loadPhpFile($file),
			default => throw new ConfigurationException("Configuration file $file must be a .neon or a .php file."),
		};
	}


	/** @throws ConfigurationException */
	private static function loadPhpFile(string $file): Config
	{
		$config = require $file;
		return $config instanceof Config
			? $config
			: throw new ConfigurationException("Configuration file $file must return DressCode\\Config.");
	}
}
