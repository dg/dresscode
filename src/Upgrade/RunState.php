<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\ConfigurationException;
use Nette\Utils\FileSystem;
use const LOCK_EX, LOCK_NB;


/**
 * What the runs of the plan of a project keep between them, in the directory of its git repository, or in the cache
 * directory where git finds none: the snapshots of the steps and the results of the tests before the first step; and
 * the lock that keeps two runs from working in the project at once.
 * @internal
 */
final readonly class RunState
{
	public Snapshots $snapshots;
	public string $directory;

	/** it lies in the directory of git, which clearing the cache does not take */
	public bool $durable;


	public function __construct(Project $project)
	{
		$gitDir = new Git($project->root)->findDirectory();
		$name = substr(hash('xxh128', $project->root), 0, 16);
		$this->directory = $gitDir === null ? $project->getCacheDir() . "/upgrade/$name" : "$gitDir/dresscode-upgrade/$name";
		$this->durable = $gitDir !== null;
		$this->snapshots = new Snapshots("$this->directory/snapshots");
	}


	/**
	 * Does the work holding the lock of the project, which another run of the plan or a plan being saved holds meanwhile.
	 * @template T
	 * @param  \Closure(): T  $work
	 * @return T
	 * @throws ConfigurationException  where another process holds it
	 */
	public function runExclusively(\Closure $work): mixed
	{
		FileSystem::createDir(dirname($this->directory));
		$handle = FileSystem::open("$this->directory.lock", 'c');
		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			throw new ConfigurationException('Another run of the upgrade works in the project right now; wait until it ends.');
		}

		try {
			return $work();
		} finally {
			fclose($handle);
		}
	}


	/**
	 * Puts back what a run keeps to go back, holding the lock of the project: the files of the steps it did not finish; a
	 * dry run only says what it would.
	 * @throws ConfigurationException  where another process holds the lock
	 */
	public function restore(Project $project, bool $dryRun = false): Restoration
	{
		return $this->runExclusively(function () use ($project, $dryRun): Restoration {
			$restored = $left = [];
			foreach ($this->snapshots->findInterrupted() as $step => $files) {
				$left += $this->snapshots->restore($step, $project->root, $dryRun);
				$restored = [...$restored, ...array_diff($files, array_keys($left))];
			}

			return new Restoration($restored, $left);
		});
	}


	/** The results the command of the tests gave before the first step; null where it has not run yet. */
	public function findBaseline(string $command): ?TestResults
	{
		return TestResults::fromFile($this->getBaselineFile($command));
	}


	public function saveBaseline(string $command, TestResults $results): void
	{
		$results->save($this->getBaselineFile($command));
	}


	/** Forgets everything, once the plan is done or another one is saved. */
	public function remove(): void
	{
		FileSystem::delete($this->directory);
	}


	private function getBaselineFile(string $command): string
	{
		return "$this->directory/tests-baseline-" . substr(hash('xxh128', $command), 0, 16) . '.json';
	}
}
