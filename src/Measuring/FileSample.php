<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use function count, strlen;


/**
 * The files a decision is measured on: every directory of the scope its share, so that a large one does not crowd the
 * others out, and every k-th file within it, so that the same tree always gives the same sample. The cost of measuring
 * is bytes and not files, so the sample has a budget of both, which every directory has its share of too.
 * @internal
 */
final readonly class FileSample
{
	/** the largest sample: every k-th of the sorted files up to this many, and up to this many bytes */
	public const MaxFiles = 300;
	public const MaxBytes = 2_000_000;

	/** a scope of no more files is measured whole, up to this many bytes */
	private const WholeFiles = 1000;
	private const WholeBytes = 8_000_000;

	/** how deep the directories the sample is shared among go, where the scope lies in one of them */
	private const MaxDepth = 4;

	/** how far into a file the mark of a generator is looked for */
	private const HeadLength = 1024;

	/** what a generator writes into the head of what it wrote */
	private const GeneratedPattern = '~@generated\b|\b(?:auto-?generated|automatically generated|do not (?:edit|modify))\b~i';


	private function __construct(
		/** @var list<string>  the sample, relative to the root */
		public array $files,
		/** @var array<string, array{int, int}>  directory the sample is shared among => the files picked of it, and of all */
		public array $directories = [],
	) {
	}


	/**
	 * The sample of the scope, all of a small one, within the limits given otherwise.
	 * @param  list<string>  $files  the scope, sorted, relative to the root
	 */
	public static function pick(string $root, array $files, ?int $maxFiles = null, ?int $maxBytes = null): self
	{
		$whole = count($files) <= self::WholeFiles;
		$maxFiles ??= $whole ? self::WholeFiles : self::MaxFiles;
		$maxBytes ??= $whole ? self::WholeBytes : self::MaxBytes;
		$groups = self::groupByDirectory($files);
		$quotas = self::share($maxFiles, array_map(count(...), $groups));
		$picked = $directories = [];
		foreach ($groups as $directory => $group) {
			if ($quotas[$directory] === 0) {
				$directories[$directory] = [0, count($group)];
				continue;
			}

			$step = max(1, (int) ceil(count($group) / $quotas[$directory]));
			$budget = $maxBytes * $quotas[$directory] / array_sum($quotas);
			$bytes = $count = 0;
			foreach ($group as $index => $file) {
				if ($index % $step !== 0) {
					continue;
				}

				$size = (int) @filesize("$root/$file"); // @ file may have been deleted meanwhile
				if ($bytes + $size <= $budget) {
					$picked[] = $file;
					$bytes += $size;
					$count++;
				}
			}

			$directories[$directory] = [$count, count($group)];
		}

		if ($picked === [] && $files !== []) { // one file over the limits is evidence, none is not
			$sizes = array_map(fn(string $file) => (int) @filesize("$root/$file"), $files); // @ file may have been deleted meanwhile
			$picked = [$files[(int) array_search(min($sizes), $sizes, strict: true)]];
			foreach ($groups as $directory => $group) {
				$directories[$directory] = [(int) in_array($picked[0], $group, true), count($group)];
			}
		}

		sort($picked, SORT_STRING);
		return new self($picked, $directories);
	}


	/**
	 * The files by the directories the sample is shared among: those of the root, or of the one directory the scope lies
	 * in, down to the depth where they part.
	 * @param  list<string>  $files
	 * @return array<string, list<string>>
	 */
	private static function groupByDirectory(array $files): array
	{
		$groups = ['' => $files];
		for ($depth = 1; count($groups) === 1 && $depth <= self::MaxDepth; $depth++) {
			$deeper = [];
			foreach ($files as $file) {
				$parts = explode('/', $file);
				$deeper[implode('/', array_slice($parts, 0, min($depth, count($parts) - 1)))][] = $file;
			}

			if (array_keys($deeper) === array_keys($groups)) { // the files lie in that directory, not below it
				break;
			}

			$groups = $deeper;
		}

		return $groups;
	}


	/**
	 * The files each directory gives the sample: an even share of what is left, a smaller directory all it has, and
	 * where there are more directories than files to give, the smaller ones none.
	 * @param  array<string, int>  $sizes
	 * @return array<string, int>
	 */
	private static function share(int $total, array $sizes): array
	{
		asort($sizes);
		$quotas = [];
		$left = count($sizes);
		foreach ($sizes as $directory => $size) {
			$quotas[$directory] = min($size, intdiv($total, $left));
			$total -= $quotas[$directory];
			$left--;
		}

		return $quotas;
	}


	/** The directory of the sample the file belongs to, the deepest that holds it; '' for one of the root. */
	public function findDirectory(string $file): string
	{
		$found = '';
		foreach (array_keys($this->directories) as $directory) {
			if (str_starts_with($file, "$directory/") && strlen($directory) > strlen($found)) {
				$found = (string) $directory;
			}
		}

		return $found;
	}


	/** Whether a comment in the head of the file says a generator wrote it. */
	public static function isGenerated(string $file): bool
	{
		$head = @file_get_contents($file, length: self::HeadLength); // @ file may have been deleted meanwhile
		return $head !== false && array_any(
			@\PhpToken::tokenize($head), // @ the lexer warns of the code, which is the check's to report
			fn(\PhpToken $token) => $token->is([T_COMMENT, T_DOC_COMMENT]) && preg_match(self::GeneratedPattern, $token->text) === 1,
		);
	}
}
