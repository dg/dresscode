<?php declare(strict_types=1);

namespace DressCode\Engine;


/**
 * Matches relative paths with slashes against glob patterns: `*` and `?` do not cross a slash, `**` does.
 * A pattern with a slash is anchored to the root, one without matches a segment at any depth; both match
 * the path itself and any directory on its way.
 * @internal
 */
final class PathGlob
{
	/** @var array<string, string> */
	private static array $cache = [];


	public static function match(string $pattern, string $path): bool
	{
		return (bool) preg_match(self::$cache[$pattern] ??= self::compile($pattern), $path);
	}


	private static function compile(string $pattern): string
	{
		$pattern = trim(str_replace('\\', '/', $pattern), '/');
		$pattern = str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
		$regex = strtr(preg_quote($pattern, '~'), ['\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']);
		$start = str_contains($pattern, '/') ? '^' : '(?:^|/)';
		return "~$start(?:$regex)(?:/|$)~";
	}
}
