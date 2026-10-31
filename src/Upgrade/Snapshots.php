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
 * The files of a step as they were before it, kept in the cache directory before the step first writes them, so that
 * a step whose tests fail goes back without git, and what the step wrote last, so that going back leaves a file
 * changed since as it is.
 * @internal
 */
final readonly class Snapshots
{
	public function __construct(
		/** the directory the snapshots of the project are kept in */
		private string $dir,
	) {
	}


	/** Keeps the text of the file before the step writes it, unless the step kept it already, and what it writes. */
	public function keep(int $step, string $path, string $before, string $after): void
	{
		$index = $this->readIndex($step);
		if (!isset($index[$path])) {
			$index[$path] = ['name' => hash('xxh128', $path)];
			FileSystem::write("$this->dir/$step/" . $index[$path]['name'], $before);
		}

		$index[$path]['written'] = hash('xxh128', $after);
		FileSystem::write("$this->dir/$step/index.json", json_encode($index, JSON_THROW_ON_ERROR));
	}


	/**
	 * Writes the files of the step back as they were before it, but those changed since the step wrote them, and
	 * forgets them.
	 * @return array<string, string>  the files left as they are, relative to the root => why
	 */
	public function restore(int $step, string $root): array
	{
		$left = [];
		foreach ($this->readIndex($step) as $path => ['name' => $name, 'written' => $written]) {
			$file = Helpers::toAbsolutePath($path, $root);
			if (hash('xxh128', (string) @file_get_contents($file)) !== $written) { // @ a file deleted since is changed too
				$left[$path] = "The file changed after step $step wrote it, so it was not written back.";
			} else {
				FileSystem::write($file, FileSystem::read("$this->dir/$step/$name"));
			}
		}

		$this->drop($step);
		return $left;
	}


	/** Forgets the files of the step, once it passed its tests, or before it runs anew. */
	public function drop(int $step): void
	{
		FileSystem::delete("$this->dir/$step");
	}


	/** @return array<string, array{name: string, written: string}>  file relative to the root => its snapshot */
	private function readIndex(int $step): array
	{
		$file = "$this->dir/$step/index.json";
		$index = is_file($file) ? json_decode(FileSystem::read($file), associative: true) : null;
		return is_array($index) ? $index : [];
	}
}
