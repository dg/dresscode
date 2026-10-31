<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function is_array;


/**
 * The files of a step as they were before it, kept in the cache directory, so that a step whose tests fail goes back
 * without git: each file the first time the step changes it.
 * @internal
 */
final readonly class Snapshots
{
	public function __construct(
		/** the directory the snapshots of the project are kept in */
		private string $dir,
	) {
	}


	/** Keeps the text of the file before the step changes it, unless the step kept it already. */
	public function keep(int $step, string $path, string $code): void
	{
		$index = $this->readIndex($step);
		if (!isset($index[$path])) {
			$index[$path] = hash('xxh128', $path);
			FileSystem::write("$this->dir/$step/" . $index[$path], $code);
			FileSystem::write("$this->dir/$step/index.json", json_encode($index, JSON_THROW_ON_ERROR));
		}
	}


	/** Writes the files of the step back as they were before it, and forgets them. */
	public function restore(int $step, string $root): void
	{
		foreach ($this->readIndex($step) as $path => $name) {
			FileSystem::write(Helpers::toAbsolutePath($path, $root), FileSystem::read("$this->dir/$step/$name"));
		}

		$this->drop($step);
	}


	/** Forgets the files of the step, once it passed its tests or the user took it. */
	public function drop(int $step): void
	{
		FileSystem::delete("$this->dir/$step");
	}


	/** @return array<string, string>  file relative to the root => the name of its snapshot */
	private function readIndex(int $step): array
	{
		$file = "$this->dir/$step/index.json";
		$index = is_file($file) ? json_decode(FileSystem::read($file), associative: true) : null;
		return is_array($index) ? $index : [];
	}
}
