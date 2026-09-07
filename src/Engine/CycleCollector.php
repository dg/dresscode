<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;


/**
 * The cycle collector of a process working through files: off while they are processed, and a collection between
 * two of them once the memory has doubled since what the last one left. A tree is cyclic and the reflection of
 * PHPStan a large graph that stays alive, so the collector PHP runs by itself scans both again and again and frees
 * little; the memory stays bounded, below three quarters of `memory_limit`.
 * @internal
 */
final class CycleCollector
{
	private const Floor = 128 * 1024 ** 2;

	private readonly bool $enabled;

	private int $threshold = self::Floor;


	public function __construct()
	{
		$this->enabled = gc_enabled();
		gc_disable();
	}


	public function afterFile(): void
	{
		if (!$this->enabled || memory_get_usage() <= $this->threshold) {
			return;
		}

		gc_collect_cycles();
		$this->threshold = max(self::Floor, min(2 * memory_get_usage(), self::findCeiling()));
	}


	/** Hands the collector back to PHP as it was. */
	public function stop(): void
	{
		if ($this->enabled) {
			gc_enable();
		}
	}


	private static function findCeiling(): int
	{
		$limit = Helpers::findMemoryLimit();
		return $limit === null ? PHP_INT_MAX : intdiv($limit * 3, 4);
	}
}
