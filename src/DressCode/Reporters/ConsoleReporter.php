<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\Engine\Diff;
use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Severity;
use function count;


/**
 * Human-readable listing: violations grouped by file, optionally with a diff of the fixes, and a summary.
 */
final class ConsoleReporter implements Reporter
{
	/** @var resource */
	private $stream;
	private bool $fix = false;


	/** @param ?resource $stream */
	public function __construct(
		$stream = null,
		private readonly bool $diff = false,
	) {
		$this->stream = $stream ?? STDOUT;
	}


	public function start(int $fileCount, bool $fix): void
	{
		$this->fix = $fix;
	}


	public function reportFile(FileResult $result): void
	{
		if (!$result->violations && !$result->warnings && $result->error === null && !$result->isChanged()) {
			return;
		}

		$this->write("$result->path\n");
		if ($result->error !== null) {
			$this->write(sprintf("  %-7s error    %s\n", $result->errorLine ?? '', $result->error));
		}

		foreach ($result->violations as $violation) {
			$position = $violation->line . ($violation->column === null ? '' : ":$violation->column");
			$marks = [];
			if ($violation->fixable) {
				$marks[] = $this->fix ? 'fixed' : 'fixable';
			}

			if ($violation->followUp) {
				$marks[] = 'follow-up';
			}

			$this->write(sprintf(
				"  %-7s %-8s %s  [%s]%s\n",
				$position,
				$violation->severity === Severity::Error ? 'error' : 'warning',
				$violation->message,
				$violation->ruleName,
				$marks ? '  (' . implode(', ', $marks) . ')' : '',
			));
		}

		foreach ($result->warnings as $warning) {
			$this->write("          warning  $warning\n");
		}

		if ($this->diff && $result->isChanged()) {
			$this->write(Diff::unified($result->code, (string) $result->output, $result->path));
		}

		$this->write("\n");
	}


	public function finish(RunResult $result): void
	{
		$violations = $result->countViolations();
		$fixable = $result->countFixable();
		$errors = $result->countErrors();
		$files = count($result->files);
		if ($this->fix) {
			$line = $fixable
				? sprintf('Fixed %s in %s', self::plural($fixable, 'violation'), self::plural($result->countChangedFiles(), 'file'))
				: sprintf('Nothing to fix in %s', self::plural($files, 'file'));
			if ($violations - $fixable) {
				$line .= sprintf(', %s remain%s', self::plural($violations - $fixable, 'violation'), $violations - $fixable === 1 ? 's' : '');
			}
		} else {
			$line = $violations
				? sprintf('Found %s%s in %s', self::plural($violations, 'violation'), $fixable ? " ($fixable fixable)" : '', self::plural($files, 'file'))
				: sprintf('No violations found in %s', self::plural($files, 'file'));
		}

		if ($errors) {
			$line .= sprintf(', %s with syntax errors', self::plural($errors, 'file'));
		}

		$this->write("$line.\n");
	}


	private static function plural(int $count, string $noun): string
	{
		return $count . ' ' . $noun . ($count === 1 ? '' : 's');
	}


	private function write(string $text): void
	{
		fwrite($this->stream, $text);
	}
}
