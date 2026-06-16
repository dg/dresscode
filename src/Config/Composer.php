<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function is_array, is_string, strlen;


/**
 * What the files of Composer say about a project: where its composer.json is, the PHP it is written for and where
 * its code lies.
 * @internal
 */
final class Composer
{
	/**
	 * The composer.json of the root or of a directory above it, the way the configuration file is looked up.
	 */
	public static function findFile(string $root): ?string
	{
		$directory = Helpers::canonicalizePath($root);
		while (true) {
			if (is_file("$directory/composer.json")) {
				return "$directory/composer.json";
			}

			$parent = dirname($directory);
			if ($parent === $directory) {
				return null;
			}

			$directory = $parent;
		}
	}


	/**
	 * What a JSON file of Composer holds; null when there is none, it cannot be read or it is not an object.
	 * @return ?array<mixed>
	 */
	public static function read(?string $file): ?array
	{
		$json = $file === null ? false : @file_get_contents($file); // @ the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		return is_array($data) ? $data : null;
	}


	/**
	 * The constraint of require.php; null where it has no lower bound (`<8.4`, `*`) or is no constraint, which leaves
	 * the version to the default rather than to a guess.
	 */
	public static function detectPhpTarget(?string $composerFile): ?string
	{
		$constraint = self::read($composerFile)['require']['php'] ?? null;
		return is_string($constraint) && Versions::findLowestVersion($constraint) !== null ? $constraint : null;
	}


	/**
	 * The directories autoload and autoload-dev name, in the order of the file, relative to the root and with
	 * their dot segments resolved: the only place where a project itself says where its code is. A `files`
	 * entry is a single file, not a scope, and a classmap may name one too, so only what is a directory
	 * counts; a directory outside the root belongs to another project, since the file may be the one of a
	 * directory above.
	 * @return list<string>
	 */
	public static function detectAutoloadPaths(?string $composerFile, string $root): array
	{
		$data = self::read($composerFile);
		if ($data === null) {
			return [];
		}

		$base = Helpers::canonicalizePath(dirname((string) $composerFile));
		$root = Helpers::canonicalizePath($root);
		$paths = [];
		foreach (['autoload', 'autoload-dev'] as $section) {
			foreach (['psr-4', 'psr-0', 'classmap'] as $kind) {
				foreach ((array) ($data[$section][$kind] ?? []) as $value) {
					foreach ((array) $value as $path) { // a psr-4 prefix takes one path or several
						// `./src` and `src` are the same path, and only resolved do they compare as one
						$directory = is_string($path) ? Helpers::canonicalizePath(FileSystem::normalizePath("$base/$path")) : null;
						if ($directory === null || !is_dir($directory)) {
							continue;
						} elseif ($directory === $root) {
							$paths['.'] = true;
						} elseif (str_starts_with($directory, "$root/")) {
							$paths[substr($directory, strlen($root) + 1)] = true;
						}
					}
				}
			}
		}

		return array_keys($paths);
	}
}
