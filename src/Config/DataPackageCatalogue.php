<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use Nette\Utils\FileSystem;
use function is_array, is_string;


/**
 * The packages of upgrading data Packagist offers, each with the libraries it says it is about under
 * `extra.dresscode.packages`, which DressCode asks for no more than once a day. For now only those of the vendor
 * `dresscode`, until a package of another vendor can be trusted.
 * @internal
 */
final class DataPackageCatalogue
{
	private const ListUrl = 'https://packagist.org/packages/list.json?vendor=dresscode&type=dresscode-plugin';
	private const MetadataUrl = 'https://repo.packagist.org/p2/%s.json';
	private const MaxAge = 86_400;

	/** how long a failed request is remembered, so that a run without the network does not wait for it every time */
	private const FailureAge = 3_600;


	/**
	 * @return array<string, list<string>>  package => the libraries it is about, a whole vendor written `vendor/`;
	 *   empty where Packagist cannot be reached and nothing is remembered
	 */
	public static function load(string $cacheDir): array
	{
		$file = "$cacheDir/data-packages.json";
		$cached = is_file($file) ? json_decode((string) file_get_contents($file), associative: true) : null;
		$cached = is_array($cached) ? $cached : null;
		if ($cached !== null && filemtime($file) > time() - self::MaxAge) {
			return $cached;
		}

		$fetched = self::fetch();
		try {
			FileSystem::write($file, json_encode($fetched ?? $cached ?? [], JSON_THROW_ON_ERROR));
			if ($fetched === null) {
				touch($file, time() - self::MaxAge + self::FailureAge);
			}
		} catch (\Throwable) { // a cache that cannot be written only means asking again
		}

		return $fetched ?? $cached ?? [];
	}


	/** @return ?array<string, list<string>>  null where Packagist does not answer */
	private static function fetch(): ?array
	{
		$names = self::request(self::ListUrl)['packageNames'] ?? null;
		if (!is_array($names)) {
			return null;
		}

		$res = [];
		foreach (array_filter($names, is_string(...)) as $name) {
			// the newest release, or the development version of a package with none
			$metadata = self::request(sprintf(self::MetadataUrl, $name))['packages'][$name][0]
				?? self::request(sprintf(self::MetadataUrl, "$name~dev"))['packages'][$name][0]
				?? null;
			$libraries = $metadata['extra']['dresscode']['packages'] ?? null;
			if (is_array($libraries) && array_all($libraries, fn($library) => is_string($library))) {
				$res[$name] = array_values($libraries);
			}
		}

		return $res;
	}


	/** @return ?array<mixed> */
	private static function request(string $url): ?array
	{
		$context = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'DressCode']]);
		$json = @file_get_contents($url, context: $context); // @ a failure is no answer
		$data = $json === false ? null : json_decode($json, associative: true);
		return is_array($data) ? $data : null;
	}
}
