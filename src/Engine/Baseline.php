<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{ConfigurationException, FileResult};
use Nette\Neon\{Exception as NeonException, Neon};
use function is_array, is_string;


/**
 * The violations a run holds back instead of recording, by file and fingerprint; generated from a run and kept in a
 * .neon or a .php file, as its extension says. A run marks the entries it matched, so the unmatched ones can be reported.
 * @internal
 */
final class Baseline
{
	/** the version of the file, a new one where the fingerprints are made otherwise */
	private const Version = 2;

	/** @var array<string, array<string, array{decision: string, message: string}>>  path => fingerprint => entry */
	private array $entries = [];

	/** @var array<string, array<string, true>>  path => fingerprints no violation matched yet */
	private array $unmatched = [];

	private int $matched = 0;


	/**
	 * The baseline of the file, or null before the first generation; the name is judged either way.
	 * @throws ConfigurationException
	 */
	public static function load(string $file): ?self
	{
		$format = self::resolveFormat($file);
		if (!is_file($file)) {
			return null;
		}

		try {
			$data = match ($format) {
				'neon' => Neon::decode((string) @file_get_contents($file)), // @ a file that vanished meanwhile decodes as empty
				'php' => require $file,
			};
		} catch (NeonException $e) {
			throw new ConfigurationException("Baseline file `$file` is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		if ($data === null) {
			return new self;
		} elseif (!is_array($data) || ($data['version'] ?? null) !== self::Version) {
			throw new ConfigurationException(
				"Baseline file `$file` is not of the version this DressCode writes; generate it again with `dresscode baseline`.",
				'suppressing#baseline',
			);
		}

		$baseline = new self;
		foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $path => $violations) {
			foreach (is_array($violations) ? $violations : [] as $violation) {
				if (
					!is_string($path)
					|| !is_array($violation)
					|| !is_string($violation['fingerprint'] ?? null)
					|| !is_string($violation['decision'] ?? null)
					|| !is_string($violation['message'] ?? null)
				) {
					throw new ConfigurationException("Baseline file `$file` has an unexpected shape.");
				}

				$baseline->add($path, $violation['fingerprint'], $violation['decision'], $violation['message']);
			}
		}

		return $baseline;
	}


	/** @param list<FileResult|FileSummary> $results */
	public static function fromResults(array $results): self
	{
		$baseline = new self;
		foreach ($results as $result) {
			foreach ($result->violations as $violation) {
				// a refused risky fix that is only uncertain waits for what the project can tell, not for the baseline
				if (!$violation->refused || !$violation->risk?->isResolvable()) {
					$baseline->add($result->path, $violation->fingerprint, $violation->decision, $violation->message);
				}
			}
		}

		return $baseline;
	}


	private function add(string $path, string $fingerprint, string $decision, string $message): void
	{
		$this->entries[$path][$fingerprint] = ['decision' => $decision, 'message' => $message];
		$this->unmatched[$path][$fingerprint] = true;
	}


	/**
	 * Writes the entries in the format the extension of the file names.
	 * @throws ConfigurationException
	 */
	public function save(string $file): void
	{
		$files = [];
		ksort($this->entries, SORT_STRING);
		foreach ($this->entries as $path => $violations) {
			foreach ($violations as $fingerprint => $entry) {
				// a fingerprint of nothing but digits came back from the array key as an int, and NEON
				// would then write it unquoted and read it back as a number the loader refuses
				$files[$path][] = $entry + ['fingerprint' => (string) $fingerprint];
			}
		}

		$content = match (self::resolveFormat($file)) {
			'neon' => Neon::encode(['version' => self::Version, 'files' => $files], blockMode: true),
			'php' => "<?php declare(strict_types=1);\n\nreturn " . self::export(['version' => self::Version, 'files' => $files], "\n") . ";\n",
		};
		if (@file_put_contents($file, $content) === false) { // @ is escalated to exception
			throw new \RuntimeException("Cannot write the baseline file `$file`.");
		}
	}


	/**
	 * The format the name of the file says, as it does for the configuration; anything else is a typo in the
	 * configured name, not a format to guess at.
	 * @return 'neon'|'php'
	 * @throws ConfigurationException
	 */
	private static function resolveFormat(string $file): string
	{
		return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
			'neon' => 'neon',
			'php' => 'php',
			default => throw new ConfigurationException("Baseline file `$file` must be a `.neon` or a `.php` file."),
		};
	}


	/** @param array<int|string, mixed> $value */
	private static function export(array $value, string $indent): string
	{
		$items = [];
		foreach ($value as $key => $item) {
			$items[] = $indent . "\t" . (is_string($key) ? var_export($key, return: true) . ' => ' : '')
				. (is_array($item) ? self::export($item, $indent . "\t") : var_export($item, return: true)) . ',';
		}

		return $items ? '[' . implode('', $items) . "$indent]" : '[]';
	}


	public function count(): int
	{
		return array_sum(array_map(count(...), $this->entries));
	}


	/** Identity of the entries, for whatever caches a result that depends on them. */
	public function getHash(): string
	{
		return hash('xxh128', serialize($this->entries));
	}


	/** Whether the baseline holds this violation of the file, which is then not recorded. */
	public function has(string $path, string $fingerprint): bool
	{
		return isset($this->entries[$path][$fingerprint]);
	}


	/**
	 * Records the entries a run matched; the run tells the baseline, because a file may have been
	 * processed by a worker with a baseline of its own.
	 * @param list<string> $fingerprints
	 */
	public function markMatched(string $path, array $fingerprints): void
	{
		foreach ($fingerprints as $fingerprint) {
			if (isset($this->unmatched[$path][$fingerprint])) {
				unset($this->unmatched[$path][$fingerprint]);
				$this->matched++;
			}
		}
	}


	/** Violations of the run the baseline matched. */
	public function countMatched(): int
	{
		return $this->matched;
	}


	/**
	 * Entries of the files of the run that no violation matched. An entry of a file the run did not process says
	 * nothing, and neither does one of a decision the run was narrowed away from.
	 * @param array<string, ?list<string>> $scope  path of a processed file => the decisions reported on it, null for any
	 */
	public function countUnmatched(array $scope): int
	{
		$count = 0;
		foreach ($scope as $path => $decisions) {
			foreach (array_keys($this->unmatched[$path] ?? []) as $fingerprint) {
				if ($decisions === null || in_array($this->entries[$path][$fingerprint]['decision'], $decisions, true)) {
					$count++;
				}
			}
		}

		return $count;
	}
}
