<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function is_array, is_int, is_string;
use const LOCK_EX, LOCK_NB;


/**
 * What the runs of the plan of a project keep between them, in the directory of its git repository, or in the cache
 * directory where git finds none: the snapshots of the steps, the files of Composer before a raise, the results of the
 * tests before the first step, and the step a run with commits left uncommitted; and the lock that keeps two runs from
 * working in the project at once.
 * @internal
 */
final readonly class RunState
{
	public Snapshots $snapshots;
	public ComposerJournal $journal;
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
		$this->journal = new ComposerJournal("$this->directory/composer");
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
	 * Puts back what a run keeps to go back, holding the lock of the project: the files of the steps it did not finish
	 * and those of Composer a raise changed, the packages of the lock installed then; a dry run only says what it would.
	 * @param  ?\Closure(string): void  $onProgress  told what is being done
	 * @throws ConfigurationException  where another process holds the lock
	 */
	public function restore(Project $project, PackageManager $composer, bool $dryRun = false, ?\Closure $onProgress = null): Restoration
	{
		return $this->runExclusively(function () use ($project, $composer, $dryRun, $onProgress): Restoration {
			$restored = $left = [];
			foreach ($this->snapshots->findInterrupted() as $step => $files) {
				$left += $this->snapshots->restore($step, $project->root, $dryRun);
				$restored = [...$restored, ...array_diff($files, array_keys($left))];
			}

			// the journal is forgotten only once nothing is left of it and what it put back is installed, so that a
			// conflict or a failed install can be gone back over
			$composerRoot = $project->findComposerRoot();
			[$composerFiles, $composerLeft] = $this->journal->restore($composerRoot, $dryRun);
			$installs = $dryRun
				? ($composerFiles !== [] || $this->journal->isAwaitingInstall()) && is_file("$composerRoot/composer.lock")
				: $this->journal->isAwaitingInstall();
			$failure = null;
			if ($installs && !$dryRun) {
				$onProgress?->__invoke('Composer, installing the packages of the lock');
				try {
					$composer->install();
					$this->journal->markInstalled();
				} catch (ComposerException $e) {
					$failure = $e->getMessage();
				}
			}

			if (!$dryRun && $composerLeft === [] && !$this->journal->isAwaitingInstall()) {
				$this->journal->forget();
			}

			return new Restoration([...$restored, ...$composerFiles], $left + $composerLeft, $installs, $failure);
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


	public function findUncommitted(): ?UncommittedStep
	{
		$file = "$this->directory/uncommitted.json";
		$data = is_file($file) ? json_decode(FileSystem::read($file), associative: true) : null;
		if (!is_array($data) || !is_int($data['step'] ?? null)) {
			return null;
		}

		$strings = fn(mixed $list) => array_values(array_filter((array) $list, is_string(...)));
		$topics = [];
		foreach ((array) ($data['topics'] ?? []) as $topic => $paths) {
			$topics[(string) $topic] = $strings($paths);
		}

		$hashes = array_filter((array) ($data['hashes'] ?? []), is_string(...));
		return new UncommittedStep(
			$data['step'],
			is_string($data['identity'] ?? null) ? $data['identity'] : '',
			$topics,
			$strings($data['unprocessed'] ?? []),
			array_combine(array_map(strval(...), array_keys($hashes)), $hashes),
		);
	}


	public function saveUncommitted(UncommittedStep $step): void
	{
		Helpers::replaceFile("$this->directory/uncommitted.json", json_encode((array) $step, JSON_THROW_ON_ERROR));
	}


	public function forgetUncommitted(): void
	{
		FileSystem::delete("$this->directory/uncommitted.json");
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
