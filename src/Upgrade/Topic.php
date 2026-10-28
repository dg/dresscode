<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * One change of an upgrade repeated over places: the library of an entry of upgrading data, or the decision of any other
 * place, in one phase, with the places counted by what the run can do about them.
 * @internal
 */
final readonly class Topic
{
	public function __construct(
		/** as `--only` takes it: `php`, `nette/application`, `upgrading.php.implicitNullable` */
		public string $name,
		/** the set whose decisions the topic is of: `deprecations`, `modernizations` or `cleanup` */
		public string $set,
		public Phase $phase,
		/** the files holding its places */
		public int $files,
		/** @var array<value-of<Fixability>, int>  the places by what the run can do about them, every case in its order */
		public array $places,
	) {
	}


	/** The places, or those the run can do the given thing about. */
	public function countPlaces(?Fixability $fixability = null): int
	{
		return $fixability === null ? array_sum($this->places) : $this->places[$fixability->value];
	}
}
