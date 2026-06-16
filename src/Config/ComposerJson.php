<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Engine\Helpers;
use function is_array, is_string;


/**
 * What the files of Composer say about a project: where its composer.json is and the PHP it is written for.
 * @internal
 */
final class ComposerJson
{
	/**
	 * The composer.json of the root or of a directory above it, the way the configuration file is looked up.
	 */
	public static function findFile(string $root): ?string
	{
		foreach (Helpers::walkUp($root) as $directory) {
			if (is_file("$directory/composer.json")) {
				return "$directory/composer.json";
			}
		}

		return null;
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
}
