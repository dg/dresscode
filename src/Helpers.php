<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use Nette\Utils\FileSystem;
use PhpSyntax;


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
	 * Matches a relative path with slashes against a glob pattern: `*` and `?` do not cross a slash, `**` does,
	 * `[abc]` and `[!abc]` are a character class. A pattern with a slash is anchored to the root, one without
	 * matches a segment at any depth; both match the path itself and any directory on its way.
	 */
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
	 * and `.` alone, unlike `FileSystem::normalizePath()`, which also returns the separator of the platform.
	 */
	public static function canonicalizePath(string $path): string
	{
		return rtrim(FileSystem::unixSlashes($path), '/');
	}


	/**
	 * Returns the code as a message writes it, a code span of Markdown: in backticks, or where the code holds a backtick
	 * of its own, in a run of them longer than any inside, padded with a space where the code starts or ends with one;
	 * empty code as the empty string of PHP. A name cannot hold a backtick, so a message writes it in backticks
	 * directly; this is for an expression, a string, a comment or a text of the configuration.
	 */
	public static function formatCode(string $code): string
	{
		return PhpSyntax\Helpers::formatCode($code);
	}
}
