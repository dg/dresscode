<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use Nette\Utils\FileSystem;
use function ini_get, is_array;


/**
 * Small operations that belong to no class of their own.
 * @internal
 */
final class Helpers
{
	/** @param list<string> $patterns */
	public static function matchesAny(array $patterns, string $path): bool
	{
		return array_any($patterns, fn(string $pattern) => self::matchGlob($pattern, $path));
	}


	/**
	 * Matches a relative path with slashes against a glob pattern: `*` and `?` do not cross a slash, `**` does, and
	 * followed by a slash it stands for no directory or any number of them, as in gitignore, `[abc]` and `[!abc]` are
	 * a character class. A pattern with a slash is anchored to the root, one without
	 * matches a segment at any depth; both match the path itself and any directory on its way.
	 */
	public static function matchGlob(string $pattern, string $path): bool
	{
		return (bool) preg_match(self::compileGlob($pattern), $path);
	}


	/** Whether the glob pattern is one `matchGlob()` reads, its character classes closed and their ranges in order. */
	public static function isGlobValid(string $pattern): bool
	{
		return @preg_match(self::compileGlob($pattern), '') !== false; // @ an invalid one is the answer
	}


	private static function compileGlob(string $pattern): string
	{
		/** @var array<string, string> $cache */
		static $cache = [];
		if (!isset($cache[$pattern])) {
			$mask = trim(FileSystem::unixSlashes($pattern), '/');
			$mask = str_starts_with($mask, './') ? substr($mask, 2) : $mask;
			$regex = strtr(preg_quote($mask, '~'), [
				'\*\*/' => '(?:.*/)?',
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

		return $cache[$pattern];
	}


	/** The `memory_limit` of the process in bytes, null where it has none. */
	public static function findMemoryLimit(): ?int
	{
		return preg_match('~^(\d+)([KMG]?)$~i', (string) ini_get('memory_limit'), $m) // -1 is no limit
			? (int) $m[1] * ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper($m[2])]
			: null;
	}


	/**
	 * The form every path is kept in here: slashes whatever the platform, no trailing one. It leaves `..`
	 * and `.` alone, unlike `FileSystem::normalizePath()`, which also returns the separator of the platform.
	 */
	public static function canonicalizePath(string $path): string
	{
		return rtrim(FileSystem::unixSlashes($path), '/');
	}


	/** An absolute path as it is, a relative one under the directory, in the form `canonicalizePath()` gives the directory. */
	public static function toAbsolutePath(string $path, string $directory): string
	{
		return FileSystem::isAbsolute($path) ? $path : self::canonicalizePath($directory) . '/' . $path;
	}


	/**
	 * The tree of sections with the value at the path of a decision, its links separated by dots; a link on the way
	 * holding no section becomes one.
	 * @param  array<string, mixed>  $tree
	 * @return array<string, mixed>
	 */
	public static function placeValue(array $tree, string $path, mixed $value): array
	{
		$links = explode('.', $path, 2);
		$link = $links[0];
		$tree[$link] = isset($links[1])
			? self::placeValue(is_array($tree[$link] ?? null) ? $tree[$link] : [], $links[1], $value)
			: $value;
		return $tree;
	}


	/**
	 * Writes through a temporary file renamed over the target, so that an interrupted run never leaves it cut short,
	 * and in place where no temporary file can be made or renamed over it; a read-only file stays unwritten, and a
	 * link keeps pointing at the file it names.
	 */
	public static function writeFile(string $file, string $content): bool
	{
		$file = realpath($file) ?: $file;
		if (!is_writable($file)) {
			return false;
		}

		$temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($temp, $content) !== false) { // @ a directory that is not writable falls back below
			$perms = @fileperms($file); // @ a file gone meanwhile has none to keep
			if ($perms !== false) {
				@chmod($temp, $perms & 0o7777); // @ a filesystem without modes has none to keep
			}

			if (@rename($temp, $file)) { // @ a mount point or a file another process holds falls back below
				return true;
			}

			@unlink($temp); // @ nothing to clean up then
		}

		return @file_put_contents($file, $content) !== false; // @ the failure is the result
	}
}
