<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Engine\FileProcessor;
use DressCode\Helpers;
use function implode;


/**
 * The processors of a run: the one every file gets, and one for every combination of overrides a file
 * can match. A variant is built once and reused, because a rule carries the options it was configured with
 * and must never be reconfigured under a file that is already being processed.
 * @internal
 */
final class FileProcessors
{
	/** @var array<string, FileProcessor>  key of the matching overrides → the processor of that combination */
	private array $processors = [];


	public function __construct(
		/** @var list<list<string>>  the patterns of every override, in the order they were written */
		private readonly array $patterns,
		/** @var \Closure(list<int>): FileProcessor */
		private readonly \Closure $build,
	) {
	}


	/** A run with no overrides: one processor for every file. */
	public static function of(FileProcessor $processor): self
	{
		return new self([], fn() => $processor);
	}


	public function getBase(): FileProcessor
	{
		return $this->processors[''] ??= ($this->build)([]);
	}


	public function get(string $path): FileProcessor
	{
		$overrides = $this->findOverrides($path);
		return $overrides === []
			? $this->getBase()
			: $this->processors[self::keyOf($overrides)] ??= ($this->build)($overrides);
	}


	/** What tells one configuration of a file from another, for whatever remembers a result. */
	public function getKey(string $path): string
	{
		return self::keyOf($this->findOverrides($path));
	}


	/**
	 * Indexes of the overrides the path matches, in the order they were written.
	 * @return list<int>
	 */
	public function findOverrides(string $path): array
	{
		$overrides = [];
		foreach ($this->patterns as $index => $patterns) {
			if (Helpers::matchesAny($patterns, $path)) {
				$overrides[] = $index;
			}
		}

		return $overrides;
	}


	/** @param list<int> $overrides */
	private static function keyOf(array $overrides): string
	{
		return implode(',', $overrides);
	}
}
