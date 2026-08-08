<?php declare(strict_types=1);

namespace DressCode\Engine;

use DressCode\Violation;
use function count;


/**
 * Outcome of a run over files.
 */
final readonly class RunResult
{
	/**
	 * @param list<FileResult> $files
	 * @param int $baselined  violations the baseline silenced
	 * @param list<string> $warnings  about the run as a whole
	 */
	public function __construct(
		public array $files,
		public bool $fix,
		public int $baselined = 0,
		public array $warnings = [],
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
	 * 0 when nothing is left to report, 1 when violations remain (after the fixes, in a fix run) or a file
	 * could not be parsed, 2 when a rule failed.
	 */
	public function getExitCode(): int
	{
		$remaining = $this->fix ? $this->countViolations() - $this->countFixable() : $this->countViolations();
		return match (true) {
			$this->countFailures() > 0 => 2,
			$remaining > 0 || $this->countErrors() > 0 => 1,
			default => 0,
		};
	}
}
