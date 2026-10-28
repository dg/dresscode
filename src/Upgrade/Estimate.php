<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What an upgrade to the targets costs: the topics the check found, what it does not see, what of the lock refuses
 * the targets, and how far the upgrading data reach.
 * @internal
 */
final readonly class Estimate
{
	public function __construct(
		/** @var list<Target> */
		public array $targets,
		/** @var list<Topic>  those of the jump first, each phase by its count of places, the largest first */
		public array $topics,
		/** the files checked */
		public int $files,
		public BlindSpots $blindSpots,
		/** @var list<Blockers>  one for every target, where the project has a lock */
		public array $blockers,
		/** @var array<string, list<string>>  a package of upgrading data DressCode lacks => the libraries of the project it is about */
		public array $missingData,
		/** @var array<string, string>  library => the newest version its upgrading data know of */
		public array $knownVersions,
		/** the run measured what the namespaces declare and resolved the names for certain, the configuration saying nothing */
		public bool $namesMeasured,
		/** the run had the types of the code */
		public bool $types,
		/** @var list<string>  what the user should be told about the run */
		public array $warnings,
	) {
	}


	/**
	 * The topics of the phase, of one set or of any.
	 * @return list<Topic>
	 */
	public function findTopics(Phase $phase, ?string $set = null): array
	{
		return array_values(array_filter(
			$this->topics,
			fn(Topic $topic) => $topic->phase === $phase && ($set === null || $topic->set === $set),
		));
	}
}
