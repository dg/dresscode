<?php declare(strict_types=1);

namespace DressCode;

use function count;


/**
 * Outcome of a run over files.
 */
final readonly class RunResult
{
	public function __construct(
		/** @var list<FileResult> */
		public array $files,
		public bool $fix,
		/** violations the baseline silenced */
		public int $baselined = 0,
		/** @var list<string> about the run as a whole */
		public array $warnings = [],
		/** how many warnings the run tolerates before the exit code says so; null for any number */
		public ?int $maxWarnings = null,
	) {
	}


	public function countViolations(): int
	{
		return array_sum(array_map(fn(FileResult $f) => count($f->violations), $this->files));
	}


	public function countFixable(): int
	{
		return array_sum(array_map(
			fn(FileResult $f) => count(array_filter($f->violations, fn(Violation $v) => $v->fixable)),
			$this->files,
		));
	}


	/** Violations that follow from another one of the same file, whose fix opened, closed or moved their line. */
	public function countDerived(?Severity $severity = null): int
	{
		return array_sum(array_map(
			fn(FileResult $f) => count(array_filter(
				$f->violations,
				fn(Violation $v) => $v->derivedFrom !== null && ($severity === null || $v->severity === $severity),
			)),
			$this->files,
		));
	}


	/** Fixes the run offered and was not allowed to make; a fix it made is not one of them. */
	public function countRiskyDeferred(): int
	{
		return array_sum(array_map(
			fn(FileResult $f) => count(array_filter($f->violations, fn(Violation $v) => $v->risky && !$v->fixable)),
			$this->files,
		));
	}


	public function countChangedFiles(): int
	{
		return count(array_filter($this->files, fn(FileResult $f) => $f->isChanged()));
	}


	/** files with a syntax error */
	public function countErrors(): int
	{
		return count(array_filter($this->files, fn(FileResult $f) => $f->error !== null));
	}


	/** files whose rules failed */
	public function countFailures(): int
	{
		return count(array_filter($this->files, fn(FileResult $f) => $f->failure !== null));
	}


	/**
	 * Violations the run leaves to the user: all of them in a check, the ones no rule fixed in a fix.
	 */
	public function countRemaining(?Severity $severity = null): int
	{
		$count = 0;
		foreach ($this->files as $file) {
			foreach ($file->violations as $violation) {
				if (
					($severity === null || $violation->severity === $severity)
					&& !($this->fix && $violation->fixable)
				) {
					$count++;
				}
			}
		}

		return $count;
	}


	/**
	 * 0 when nothing is left to report, 1 when violations remain (after the fixes, in a fix run), a file
	 * could not be parsed or the warnings passed the threshold, 2 when a rule failed.
	 */
	public function getExitCode(): int
	{
		return match (true) {
			$this->countFailures() > 0 => 2,
			$this->countRemaining(Severity::Error) > 0 || $this->countErrors() > 0 => 1,
			$this->maxWarnings !== null && $this->countRemaining(Severity::Warning) > $this->maxWarnings => 1,
			default => 0,
		};
	}
}
