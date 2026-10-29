<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function is_array, is_string;


/**
 * The files of a step as they were before it, kept with the state of the run before the step first writes them, so that
 * a step whose tests fail goes back without git, and what the step wrote last, so that going back leaves a file
 * changed since as it is and keeps its text before the step.
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
	public function record(int $step, string $path, string $before, string $after): void
	{
		$index = $this->readIndex($step) ?? throw $this->createUnreadable($step);
		if (!isset($index[$path])) {
			$index[$path] = ['name' => hash('xxh128', $path)];
			Helpers::replaceFile("$this->dir/$step/" . $index[$path]['name'], $before);
		}

		$index[$path]['written'] = hash('xxh128', $after);
		Helpers::replaceFile("$this->dir/$step/index.json", json_encode($index, JSON_THROW_ON_ERROR));
	}


	/**
	 * Writes the files of the step back as they were before it and forgets them, but those changed since the step wrote
	 * them, which are left as they are and whose text before the step stays kept; a file still as it was before, which
	 * a run interrupted before writing it leaves, is only forgotten. A dry run only says which files it would leave.
	 * @return array<string, string>  the files left as they are, relative to the root => why
	 */
	public function restore(int $step, string $root, bool $dryRun = false): array
	{
		$index = $this->readIndex($step) ?? throw $this->createUnreadable($step);
		$left = [];
		foreach ($index as $path => ['name' => $name, 'written' => $written]) {
			$file = Helpers::toAbsolutePath($path, $root);
			$current = @file_get_contents($file); // @ a file deleted since is changed too
			$before = FileSystem::read("$this->dir/$step/$name");
			if ($current !== $before && hash('xxh128', (string) $current) !== $written) {
				$left[$path] = "The file changed after step $step wrote it, so it is not written back; its text before the step is kept in `$this->dir/$step/$name`.";
			} elseif ($dryRun) {
				continue;
			} elseif ($current !== $before && !Helpers::writeFile($file, $before)) {
				$left[$path] = "The file cannot be written, so it is not written back; its text before the step is kept in `$this->dir/$step/$name`.";
			} else {
				FileSystem::delete("$this->dir/$step/$name");
				unset($index[$path]);
			}
		}

		if ($dryRun) {
			return $left;
		} elseif ($index === []) {
			$this->drop($step);
		} else {
			Helpers::replaceFile("$this->dir/$step/index.json", json_encode($index, JSON_THROW_ON_ERROR));
		}

		return $left;
	}


	/** Forgets the files of the step, once it passed its tests. */
	public function drop(int $step): void
	{
		FileSystem::delete("$this->dir/$step");
	}


	/**
	 * The files of the steps a run left without passing or going back entirely, as one interrupted does; none where the
	 * snapshots of the step cannot be read.
	 * @return array<int, list<string>>  step => its files relative to the root
	 */
	public function findInterrupted(): array
	{
		$steps = [];
		foreach (glob("$this->dir/*/index.json") ?: [] as $file) {
			$step = (int) basename(dirname($file));
			$steps[$step] = array_keys($this->readIndex($step) ?? []);
		}

		ksort($steps);
		return $steps;
	}


	/** @return ?array<string, array{name: string, written: string}>  file relative to the root => its snapshot; null where unreadable */
	private function readIndex(int $step): ?array
	{
		$file = "$this->dir/$step/index.json";
		if (!is_file($file)) {
			return [];
		}

		$index = json_decode((string) @file_get_contents($file), associative: true); // @ the failure is told by the result
		return is_array($index)
			&& array_all(
				$index,
				fn($entry) => is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['written'] ?? null),
			)
			? $index
			: null;
	}


	private function createUnreadable(int $step): \RuntimeException
	{
		return new \RuntimeException("The snapshots of step $step in `$this->dir/$step` cannot be read; take the files of the step back with git, or `dresscode upgrade run --discard` drops them.");
	}
}
