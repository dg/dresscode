<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException};
use DressCode\Engine\Helpers;
use function count;


/**
 * Finds and loads the configuration file; without one the caller's default applies, and without that there is no style
 * to run.
 * @internal
 */
final class Loader
{
	/** the two formats, in no order of preference: only one of them may lie in a directory */
	public const FileNames = ['dresscode.neon', 'dresscode.php'];

	/** the committed template a local file replaces whole */
	public const DistSuffix = '.dist';


	/**
	 * @param  ?string  $file  the configuration file, or null to search from the directory upwards
	 * @param  ?Config  $default  what applies when there is no configuration file
	 * @return array{Config, string, ?string}  the configuration, the root directory with slashes, and the file it came from
	 * @throws ConfigurationException
	 */
	public static function load(?string $file, string $directory, ?Config $default = null): array
	{
		$file ??= self::find($directory);
		if ($file === null) {
			$config = $default ?? throw new ConfigurationException(
				"No `dresscode.neon` or `dresscode.php` found in `$directory` or above it, so there is no dress code to check against. Run `dresscode init` to have one made to measure from the code, or name a standard with `--use`.",
				docs: 'cli#init',
			);
			$root = $directory;
		} else {
			$config = self::loadFile($file);
			$root = dirname($file);
		}

		$root = realpath($root) ?: $root;
		return [$config, Helpers::canonicalizePath($root), $file];
	}


	/**
	 * The nearest configuration file in the directory or above it: within a directory the local file wins
	 * over the .dist template, and the two formats are an ambiguity nobody can resolve for the user.
	 * @throws ConfigurationException
	 */
	public static function find(string $directory): ?string
	{
		foreach (Helpers::walkUp($directory) as $directory) {
			foreach (['', self::DistSuffix] as $suffix) {
				$found = array_values(array_filter(
					array_map(fn(string $name) => "$directory/$name$suffix", self::FileNames),
					is_file(...),
				));
				if (count($found) > 1) {
					throw new ConfigurationException('Both `' . implode('` and `', $found) . '` exist; keep one of them.');
				} elseif ($found) {
					return $found[0];
				}
			}
		}

		return null;
	}


	/**
	 * The configuration files lying in the directory itself, the local ones and the .dist templates.
	 * @return list<string>  their names
	 */
	public static function listFiles(string $directory): array
	{
		return array_values(array_filter(
			array_merge(...array_map(fn(string $name) => [$name, $name . self::DistSuffix], self::FileNames)),
			fn(string $name) => is_file("$directory/$name"),
		));
	}


	/** The format follows the extension of the file, a .dist template that of the file it stands for. */
	public static function loadFile(string $file): Config
	{
		if (!is_file($file)) {
			throw new ConfigurationException("Configuration file `$file` does not exist.");
		}

		return match (self::detectFormat($file)) {
			'neon' => NeonReader::read($file),
			'php' => self::loadPhpFile($file),
			null => throw new ConfigurationException("Configuration file `$file` must be a `.neon` or a `.php` file."),
		};
	}


	/**
	 * The format the name of a configuration file says, a .dist template that of the file it stands for; null for
	 * a name that says neither.
	 * @return 'neon'|'php'|null
	 */
	public static function detectFormat(string $file): ?string
	{
		$name = (string) preg_replace('~\\' . self::DistSuffix . '$~', '', $file);
		return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
			'neon' => 'neon',
			'php' => 'php',
			default => null,
		};
	}


	/** @throws ConfigurationException */
	private static function loadPhpFile(string $file): Config
	{
		try {
			$config = require $file;
		} catch (\InvalidArgumentException|\Error $e) { // a value the configuration refuses, or a call PHP refuses, is an error of the file
			$line = $e instanceof \Error && $e->getFile() === (realpath($file) ?: $file) ? " on line {$e->getLine()}" : '';
			throw new ConfigurationException("Configuration file `$file`: {$e->getMessage()}$line", previous: $e);
		}

		return $config instanceof Config
			? $config
			: throw new ConfigurationException("Configuration file `$file` must return `DressCode\\Config`.");
	}
}
