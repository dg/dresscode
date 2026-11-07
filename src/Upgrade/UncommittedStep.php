<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * A step a run with commits left uncommitted, as some of its files could not be processed; a later run that processes
 * them commits it whole.
 * @internal
 */
final readonly class UncommittedStep
{
	public function __construct(
		public int $step,
		/** what the step is, which a plan edited since may say of another step of its number */
		public string $identity,
		/** @var array<string, list<string>>  topic => the files its fix wrote, relative to the root */
		public array $topics,
		/** @var list<string>  the files the step could not process, relative to the root */
		public array $unprocessed,
		/** @var array<string, string>  a file the step wrote => the hash of the text it wrote last */
		public array $hashes = [],
	) {
	}


	/** What the step does, its title and whether it is left out aside. */
	public static function identify(Step $step): string
	{
		return hash('xxh128', serialize([$step::class, array_diff_key(get_object_vars($step), ['title' => true, 'skip' => true])]));
	}


	/**
	 * The files whose changes are the step's: those it could not process, which the user is to change, and those it wrote
	 * that nobody changed since; a file it wrote that changed is a change of the working tree of its own.
	 * @return list<string>
	 */
	public function listOwnFiles(string $root): array
	{
		$written = array_filter(
			array_unique(array_merge(...array_values($this->topics))),
			fn(string $path) => hash('xxh128', (string) @file_get_contents("$root/$path")) === ($this->hashes[$path] ?? null), // @ a file deleted since is changed
		);
		return [...array_values($written), ...$this->unprocessed];
	}
}
