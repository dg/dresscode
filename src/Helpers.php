<?php declare(strict_types=1);

namespace DressCode;

use Nette\Utils\FileSystem;


/**
 * Small operations that belong to no class of their own.
 * @internal
 */
final class Helpers
{
	/**
	 * Matches a relative path with slashes against a glob pattern: `*` and `?` do not cross a slash, `**` does,
	 * `[abc]` and `[!abc]` are a character class. A pattern with a slash is anchored to the root, one without
	 * matches a segment at any depth; both match the path itself and any directory on its way.
	 */
	/** @param list<string> $patterns */
	public static function matchesAny(array $patterns, string $path): bool
	{
		foreach ($patterns as $pattern) {
			if (self::matchGlob($pattern, $path)) {
				return true;
			}
		}

		return false;
	}


	public static function matchGlob(string $pattern, string $path): bool
	{
		/** @var array<string, string> $cache */
		static $cache = [];
		if (!isset($cache[$pattern])) {
			$mask = trim(FileSystem::unixSlashes($pattern), '/');
			$mask = str_starts_with($mask, './') ? substr($mask, 2) : $mask;
			$regex = strtr(preg_quote($mask, '~'), [
				'\*\*' => '.*',
				'\*' => '[^/]*',
				'\?' => '[^/]',
				'\[\!' => '[^',
				'\[' => '[',
				'\]' => ']',
				'\-' => '-',
			]);
			$start = str_contains($mask, '/') ? '^' : '(?:^|/)';
			$cache[$pattern] = "~$start(?:$regex)(?:/|$)~D";
		}

		return (bool) preg_match($cache[$pattern], $path);
	}


	/**
	 * The form every path is kept in here: slashes whatever the platform, no trailing one. It leaves `..`
	 * and `.` alone, unlike FileSystem::normalizePath(), which also returns the separator of the platform.
	 */
	public static function canonicalizePath(string $path): string
	{
		return rtrim(FileSystem::unixSlashes($path), '/');
	}
}
