<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\Engine\RunResult;
use function count, is_array;


/**
 * What a check `dresscode init` runs tells, counted by decision: where the code disagrees with the configuration and
 * what a fix would change. A violation that follows from another one is no place of its own and counts nowhere.
 * @internal
 */
final readonly class ProbeResult
{
	/** the findings of a decision kept for what a question shows of the code, the rest only counted */
	private const KeptFindings = 1000;


	public function __construct(
		/** @var array<string, array<string, int>>  decision => file => the places the decision reports in it */
		public array $places,
		/** @var array<string, array<string, true>>  decision => the files a fix of it without risk would change */
		public array $fixable,
		/** @var array<string, array<string, int>>  decision => file => the places a fix leaves, having no fix or a risky one */
		public array $left,
		/** the files a fix would change */
		public int $changedFiles,
		/** @var list<string>  the files that do not parse */
		public array $syntaxErrors,
		/** @var list<string>  the files a rule failed in */
		public array $failed,
		/** @var array<string, list<Finding>>  decision => the first places it reports */
		public array $findings = [],
	) {
	}


	public static function fromRun(RunResult $run): self
	{
		$files = [];
		foreach ($run->files as $result) {
			$violations = [];
			foreach ($result->violations as $violation) {
				$violations[] = [$violation->decision, $violation->derivedFrom !== null, new Finding(
					$result->path,
					$violation->line,
					$violation->message,
					$violation->fixable && $violation->risk === null,
					$violation->offset === null ? null : [$violation->offset, (int) $violation->length],
				)];
			}

			$files[] = [$result->path, $violations, $result->changed, $result->syntaxError !== null, $result->failure !== null];
		}

		return self::collect($files);
	}


	/**
	 * The report of `check --format json`.
	 * @param  array<mixed>  $report
	 */
	public static function fromJson(array $report): self
	{
		$files = [];
		foreach (is_array($report['files'] ?? null) ? $report['files'] : [] as $file) {
			$violations = [];
			foreach ($file['violations'] ?? [] as $violation) {
				$violations[] = [(string) $violation['decision'], $violation['derivedFrom'] !== null, new Finding(
					(string) $file['path'],
					(int) $violation['line'],
					(string) $violation['message'],
					$violation['fixable'] && $violation['risk'] === null,
					isset($violation['offset']) ? [(int) $violation['offset'], (int) $violation['length']] : null,
				)];
			}

			$files[] = [
				(string) $file['path'],
				$violations,
				(bool) $file['changed'],
				$file['syntaxError'] !== null,
				$file['failure'] !== null,
			];
		}

		return self::collect($files);
	}


	/**
	 * @param  list<array{string, list<array{string, bool, Finding}>, bool, bool, bool}>  $files  path, the violations
	 *     (decision, derived, what it reports), changed, unparsed, failed
	 */
	private static function collect(array $files): self
	{
		$places = $fixable = $left = $syntaxErrors = $failed = $findings = [];
		$changed = 0;
		foreach ($files as [$path, $violations, $isChanged, $unparsed, $isFailed]) {
			$changed += (int) $isChanged;
			if ($unparsed) {
				$syntaxErrors[] = $path;
			} elseif ($isFailed) {
				$failed[] = $path;
			}

			foreach ($violations as [$decision, $derived, $finding]) {
				if ($derived) {
					continue;
				}

				$places[$decision][$path] = ($places[$decision][$path] ?? 0) + 1;
				if (count($findings[$decision] ?? []) < self::KeptFindings) {
					$findings[$decision][] = $finding;
				}

				if ($finding->fixable) {
					$fixable[$decision][$path] = true;
				} else {
					$left[$decision][$path] = ($left[$decision][$path] ?? 0) + 1;
				}
			}
		}

		return new self($places, $fixable, $left, $changed, $syntaxErrors, $failed, $findings);
	}


	/**
	 * The decisions the code disagrees with somewhere.
	 * @return list<string>
	 */
	public function findResidue(): array
	{
		return array_keys($this->places);
	}


	/** A place the decision reports, a fixable one where there is one; null for none. */
	public function findExample(string $decision): ?Finding
	{
		$findings = $this->findings[$decision] ?? [];
		return array_find($findings, fn(Finding $finding) => $finding->fixable) ?? $findings[0] ?? null;
	}


	/** How many files the decision reports in. */
	public function countFiles(string $decision): int
	{
		return count($this->places[$decision] ?? []);
	}


	/** How many places the decision reports. */
	public function countPlaces(string $decision): int
	{
		return array_sum($this->places[$decision] ?? []);
	}
}
