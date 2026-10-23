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
		/** @var array<string, list<array{string, int, string, bool}>>  decision => file, line, message, fixable without risk */
		public array $findings = [],
	) {
	}


	public static function fromRun(RunResult $run): self
	{
		$files = [];
		foreach ($run->files as $result) {
			$violations = [];
			foreach ($result->violations as $violation) {
				$violations[] = [
					$violation->decision,
					$violation->fixable && $violation->risk === null,
					$violation->derivedFrom !== null,
					$violation->line,
					$violation->message,
				];
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
				$violations[] = [
					(string) $violation['decision'],
					$violation['fixable'] && $violation['risk'] === null,
					$violation['derivedFrom'] !== null,
					(int) $violation['line'],
					(string) $violation['message'],
				];
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
	 * @param  list<array{string, list<array{string, bool, bool, int, string}>, bool, bool, bool}>  $files  path, the
	 *     violations (decision, fixable without risk, derived, line, message), changed, unparsed, failed
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

			foreach ($violations as [$decision, $isFixable, $derived, $line, $message]) {
				if ($derived) {
					continue;
				}

				$places[$decision][$path] = ($places[$decision][$path] ?? 0) + 1;
				if (count($findings[$decision] ?? []) < self::KeptFindings) {
					$findings[$decision][] = [$path, $line, $message, $isFixable];
				}

				if ($isFixable) {
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


	/** A place the decision reports, `path:line  message`, a fixable one where there is one; null for none. */
	public function findExample(string $decision): ?string
	{
		$findings = $this->findings[$decision] ?? [];
		[$path, $line, $message] = array_find($findings, fn(array $finding) => $finding[3]) ?? $findings[0] ?? [null, 0, ''];
		return $path === null ? null : "$path:$line  $message";
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
