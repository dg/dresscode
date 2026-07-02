<?php declare(strict_types=1);

namespace DressCode\Tools\PhpStan;


final class PathMatcher
{
	/**
	 * Checks whether the file lies under one of the paths, given relative to the project root with forward slashes.
	 * @param list<string> $paths
	 */
	public static function matches(string $file, array $paths): bool
	{
		$file = strtr($file, '\\', '/');
		foreach ($paths as $path) {
			if (str_contains($file, '/' . $path)) {
				return true;
			}
		}

		return false;
	}
}
