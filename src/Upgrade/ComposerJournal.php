<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function is_array, is_int, is_string;


/**
 * The files of Composer as they were before the first raise of a run and as the raise left them, so that
 * `upgrade abort` puts them back, a file changed since aside; kept until a step after the raise is done, or until what
 * was put back is installed and no file is left.
 * @internal
 */
final readonly class ComposerJournal
{
	private const Files = ['composer.json', 'composer.lock'];


	public function __construct(
		/** the directory the journal of the project is kept in */
		private string $dir,
	) {
	}


	/** Keeps the files as they are before the raise of the step, unless the journal keeps them from an earlier raise. */
	public function recordBefore(int $step, string $root): void
	{
		if ($this->readIndex() !== null) {
			return;
		}

		$before = [];
		foreach (self::Files as $name) {
			$text = @file_get_contents("$root/$name"); // @ a file the project does not have
			if ($text !== false) {
				Helpers::replaceFile("$this->dir/$name", $text);
			}

			$before[$name] = $text !== false;
		}

		$this->writeIndex(['step' => $step, 'before' => $before, 'after' => self::hashFiles($root)]);
	}


	/** Keeps what the raise left, which a file must still be for `restore()` to write it back. */
	public function recordAfter(string $root): void
	{
		$index = $this->readIndex();
		if ($index !== null) {
			$this->writeIndex(['after' => self::hashFiles($root)] + $index);
		}
	}


	/** The step whose raise the journal goes back before; null where it keeps nothing. */
	public function findStep(): ?int
	{
		return $this->readIndex()['step'] ?? null;
	}


	/**
	 * The files the raise changed.
	 * @return list<string>
	 */
	public function findChanged(): array
	{
		$index = $this->readIndex();
		return $index === null
			? []
			: array_values(array_filter(
				self::Files,
				fn(string $name) => $this->hashKept($name, $index['before'][$name] ?? false) !== ($index['after'][$name] ?? null),
			));
	}


	/** Whether files were written back with a lock beside them, whose packages are still to be installed. */
	public function isAwaitingInstall(): bool
	{
		return $this->readIndex()['awaitingInstall'] ?? false;
	}


	/** Says that the packages of the lock written back are installed. */
	public function markInstalled(): void
	{
		$index = $this->readIndex();
		if ($index !== null) {
			$this->writeIndex(['awaitingInstall' => false] + $index);
		}
	}


	/**
	 * Writes the files back as they were before the raise, but a file changed after the raise, which is left as it is
	 * and stays kept, so that it can be put back once resolved; a file written back is what the raise left from then on.
	 * A dry run only says which files it would write back and which it would leave.
	 * @return array{list<string>, array<string, string>}  the files written back, those left => why
	 */
	public function restore(string $root, bool $dryRun = false): array
	{
		$index = $this->readIndex();
		if ($index === null) {
			return [[], []];
		}

		$restored = $left = [];
		foreach (self::Files as $name) {
			$kept = $index['before'][$name] ?? false;
			$current = self::hashFile("$root/$name");
			if ($current === $this->hashKept($name, $kept)) {
				continue;
			} elseif ($current !== ($index['after'][$name] ?? null)) {
				$left[$name] = "The file changed after the raise of step {$index['step']}, so it is not written back.";
			} elseif ($dryRun) {
				$restored[] = $name;
			} elseif ($kept) {
				Helpers::replaceFile("$root/$name", FileSystem::read("$this->dir/$name"));
				$restored[] = $name;
			} else {
				FileSystem::delete("$root/$name");
				$restored[] = $name;
			}
		}

		if (!$dryRun && $restored !== []) {
			foreach ($restored as $name) {
				$index['after'][$name] = self::hashFile("$root/$name");
			}

			$index['awaitingInstall'] = is_file("$root/composer.lock");
			$this->writeIndex($index);
		}

		return [$restored, $left];
	}


	public function forget(): void
	{
		FileSystem::delete($this->dir);
	}


	private function hashKept(string $name, bool $kept): ?string
	{
		return $kept ? self::hashFile("$this->dir/$name") : null;
	}


	/** @return array<string, ?string> */
	private static function hashFiles(string $root): array
	{
		return array_combine(self::Files, array_map(fn(string $name) => self::hashFile("$root/$name"), self::Files));
	}


	private static function hashFile(string $file): ?string
	{
		$text = @file_get_contents($file); // @ a file that does not exist has no hash
		return $text === false ? null : hash('xxh128', $text);
	}


	/** @return ?array{step: int, before: array<string, bool>, after: array<string, ?string>, awaitingInstall?: bool} */
	private function readIndex(): ?array
	{
		$index = json_decode((string) @file_get_contents("$this->dir/index.json"), associative: true); // @ no journal is kept
		return is_array($index) && is_int($index['step'] ?? null) && is_array($index['before'] ?? null) && is_array($index['after'] ?? null)
			? [
				'step' => $index['step'],
				'before' => array_map(fn($kept) => $kept === true, $index['before']),
				'after' => array_map(fn($hash) => is_string($hash) ? $hash : null, $index['after']),
				'awaitingInstall' => ($index['awaitingInstall'] ?? false) === true,
			]
			: null;
	}


	/** @param  array{step: int, before: array<string, bool>, after: array<string, ?string>, awaitingInstall?: bool}  $index */
	private function writeIndex(array $index): void
	{
		Helpers::replaceFile("$this->dir/index.json", json_encode($index, JSON_THROW_ON_ERROR));
	}
}
