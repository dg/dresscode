<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Engine\FileProcessor;
use DressCode\Helpers;
use function implode;


/**
 * The processors of a run: the one every file gets, and one for every combination of `for` blocks a file
 * can match. A variant is built once and reused, because a rule carries the options it was configured with
 * and must never be reconfigured under a file that is already being processed.
 * @internal
 */
final class FileProcessors
{
	/** @var array<string, FileProcessor>  key of the matching blocks → the processor of that combination */
	private array $processors = [];


	public function __construct(
		/** @var list<list<string>>  the patterns of every block, in the order they were written */
		private readonly array $patterns,
		/** @var \Closure(list<int>): FileProcessor */
		private readonly \Closure $build,
	) {
	}


	/** A run with no blocks: one processor for every file. */
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
		$blocks = $this->findBlocks($path);
		return $blocks === []
			? $this->getBase()
			: $this->processors[self::keyOf($blocks)] ??= ($this->build)($blocks);
	}


	/** What tells one configuration of a file from another, for whatever remembers a result. */
	public function getKey(string $path): string
	{
		return self::keyOf($this->findBlocks($path));
	}


	/**
	 * Indexes of the blocks the path matches, in the order they were written.
	 * @return list<int>
	 */
	public function findBlocks(string $path): array
	{
		$blocks = [];
		foreach ($this->patterns as $index => $patterns) {
			if (Helpers::matchesAny($patterns, $path)) {
				$blocks[] = $index;
			}
		}

		return $blocks;
	}


	/** @param list<int> $blocks */
	private static function keyOf(array $blocks): string
	{
		return implode(',', $blocks);
	}
}
