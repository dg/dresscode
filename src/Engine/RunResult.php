<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{Severity, Violation};
use function count;


/**
 * Outcome of a run over files.
 * @internal
 */
final readonly class RunResult
{
	public function __construct(
		/** @var list<FileSummary>  without their code and output, which a reporter sees in `reportFile()` */
		public array $files,
		public bool $fix,
		/** violations the baseline matched */
		public int $baselined = 0,
		/** @var list<string> about the run as a whole */
		public array $warnings = [],
		/** how many warnings the run tolerates before the exit code says so; null for any number */
		public ?int $maxWarnings = null,
	) {
	}


	/** Violations the files have as they were read. */
	public function countViolations(?Severity $severity = null): int
	{
		return self::countBySeverity(array_merge(...array_map(fn(FileSummary $f) => $f->violations, $this->files)), $severity);
	}


	/**
	 * Violations a fix leaves, in a check as in a fix: what the fixed text of every file still violates, which is
	 * what the next check of it reports.
	 */
	public function countRemaining(?Severity $severity = null): int
	{
		return self::countBySeverity(array_merge(...array_map(fn(FileSummary $f) => $f->remaining, $this->files)), $severity);
	}


	/** Violations left to the user that follow from another one of the same file, whose fix opened, closed or moved their line. */
	public function countDerived(?Severity $severity = null): int
	{
		return self::countBySeverity(array_filter($this->listReported(), fn(Violation $v) => $v->derivedFrom !== null), $severity);
	}


	/** Fixes a fix leaves because the run did not allow them; a fix it made is not one of them. */
	public function countRefused(): int
	{
		return count($this->listRefused());
	}


	public function countChangedFiles(): int
	{
		return count(array_filter($this->files, fn(FileSummary $f) => $f->changed));
	}


	/** files with a syntax error */
	public function countSyntaxErrors(): int
	{
		return count(array_filter($this->files, fn(FileSummary $f) => $f->syntaxError !== null));
	}


	/** files that failed: a rule threw, the rules did not converge, or the file changed before it was written */
	public function countFailures(): int
	{
		return count(array_filter($this->files, fn(FileSummary $f) => $f->failure !== null));
	}


	/**
	 * Violations the run leaves to the user: all of them in a check, what the fixed text still violates in a fix.
	 */
	public function countReported(?Severity $severity = null): int
	{
		return self::countBySeverity($this->listReported(), $severity);
	}


	/**
	 * 0 when nothing is left to report, 1 when violations remain (after the fixes, in a fix run), a file
	 * could not be parsed or the warnings passed the threshold, 2 when a file failed.
	 */
	public function getExitCode(): int
	{
		return match (true) {
			$this->countFailures() > 0 => 2,
			$this->countReported(Severity::Error) > 0 || $this->countSyntaxErrors() > 0 => 1,
			$this->maxWarnings !== null && $this->countReported(Severity::Warning) > $this->maxWarnings => 1,
			default => 0,
		};
	}


	/** @return list<Violation> */
	private function listReported(): array
	{
		return array_merge(...array_map(fn(FileSummary $f) => $this->fix ? $f->remaining : $f->violations, $this->files));
	}


	/** @return list<Violation> */
	private function listRefused(): array
	{
		return array_values(array_filter(
			array_merge(...array_map(fn(FileSummary $f) => $f->remaining, $this->files)),
			fn(Violation $v) => $v->refused,
		));
	}


	/** @param array<Violation> $violations */
	private static function countBySeverity(array $violations, ?Severity $severity): int
	{
		return count(array_filter($violations, fn(Violation $v) => $severity === null || $v->severity === $severity));
	}
}
