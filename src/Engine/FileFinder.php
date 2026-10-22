<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\ConfigurationException;
use Nette\Utils\{FileSystem, Finder};
use function strlen;


/**
 * The files of a project a run takes: under the paths, of the extensions, minus the paths left out. Paths are relative
 * to the root, with slashes.
 * @internal
 */
final readonly class FileFinder
{
	public string $root;


	public function __construct(
		string $root,
		/** @var list<string>  patterns of paths left out */
		private array $excludePaths = [],
		/** @var list<string> */
		private array $fileExtensions = ['php'],
	) {
		$this->root = Helpers::canonicalizePath($root);
	}


	/**
	 * Files under the paths with one of the extensions, minus the excluded ones; an explicitly given file
	 * is taken as is, unless `$skipExcluded` lets the excluded paths leave it out like a found one, and so the
	 * configured paths where they are given, which is what a hook or an editor naming every file it touches wants;
	 * a file outside the root has no path the patterns could match. Sorted, relative to the root.
	 * @param  list<string>  $paths
	 * @param  list<string>  $configured  the configured paths, outside which `$skipExcluded` leaves a named file out
	 * @return list<string>
	 * @throws ConfigurationException  for a path that does not exist
	 */
	public function findFiles(array $paths, bool $skipExcluded = false, array $configured = []): array
	{
		$files = [];
		foreach ($paths as $path) {
			$path = $this->relativize($path);
			$absolute = $this->toAbsolute($path);
			if (is_file($absolute)) {
				if (!$skipExcluded || !$this->excludes($path, $configured)) {
					$files[$path] = true;
				}
			} elseif (is_dir($absolute)) {
				$finder = Finder::findFiles(array_map(fn($ext) => "*.$ext", $this->fileExtensions))
					->from($absolute)
					->descentFilter(
						fn(\SplFileInfo $dir) => !Helpers::matchesAny($this->excludePaths, $this->relativize($dir->getPathname())),
					);
				foreach ($finder as $file) {
					$relative = $this->relativize($file->getPathname());
					if (!Helpers::matchesAny($this->excludePaths, $relative)) {
						$files[$relative] = true;
					}
				}
			} else {
				throw new ConfigurationException("Path `$path` does not exist.");
			}
		}

		$files = array_keys($files);
		sort($files, SORT_STRING);
		return $files;
	}


	/**
	 * Whether the excluded paths leave out a file named by its path, or the configured paths do not hold it; a file
	 * outside the root has no path the patterns could match, so only configured paths leave it out.
	 * @param  list<string>  $configured
	 */
	public function excludes(string $path, array $configured = []): bool
	{
		$path = $this->relativize($path);
		$configured = array_map($this->relativize(...), $configured);
		if (FileSystem::isAbsolute($path)) {
			return $configured !== [];
		}

		return Helpers::matchesAny($this->excludePaths, $path) || ($configured !== [] && !array_any(
			$configured,
			fn(string $inner) => $inner === '' || $path === $inner || str_starts_with($path, "$inner/"),
		));
	}


	/**
	 * The paths named within the configured ones: a directory holding some of those stands for them, anything else
	 * for itself, since whoever names a path outside them means it.
	 * @param  list<string>  $paths
	 * @param  list<string>  $configured
	 * @return list<string>
	 */
	public function narrowPaths(array $paths, array $configured): array
	{
		$configured = array_map($this->relativize(...), $configured);
		$narrowed = [];
		foreach ($paths as $path) {
			$relative = $this->relativize($path);
			$held = in_array($relative, $configured, true)
				? []
				: array_filter($configured, fn(string $inner) => $relative === '' || str_starts_with("$inner/", "$relative/"));
			array_push($narrowed, ...($held ?: [$path]));
		}

		return array_values(array_unique($narrowed));
	}


	/**
	 * A path outside the root stays absolute, a relative one is under the root.
	 */
	public function toAbsolute(string $path): string
	{
		return match (true) {
			$path === '' => $this->root,
			FileSystem::isAbsolute($path) => $path,
			default => $this->root . '/' . $path,
		};
	}


	/**
	 * Path with slashes, relative to the root when it lies under it.
	 */
	public function relativize(string $path): string
	{
		$path = Helpers::canonicalizePath($path);
		if ($path === $this->root) {
			return '';
		} elseif (str_starts_with($path, $this->root . '/')) {
			$path = substr($path, strlen($this->root) + 1);
		}

		return implode('/', array_filter(explode('/', $path), fn(string $segment) => $segment !== '.')); // "./a" and "." would match the ".*" exclusion
	}
}
