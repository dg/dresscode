<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\Engine\Diff;
use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Severity;
use Nette\CommandLine\Console;
use function count;


/**
 * Human-readable listing: violations grouped by file, optionally with a diff of the fixes, and a summary.
 */
final class ConsoleReporter implements Reporter
{
	/** @var resource */
	private $stream;
	private Console $console;
	private bool $fix = false;


	/**
	 * @param ?resource $stream
	 * @param ?Console $console  colors; plain output when omitted
	 */
	public function __construct(
		$stream = null,
		private readonly bool $diff = false,
		?Console $console = null,
	) {
		$this->stream = $stream ?? STDOUT;
		if ($console === null) {
			$console = new Console;
			$console->useColors(false);
		}

		$this->console = $console;
	}


	public function start(int $fileCount, bool $fix): void
	{
		$this->fix = $fix;
	}


	public function reportFile(FileResult $result): void
	{
		if (
		    !$result->violations
		    && !$result->warnings
		    && $result->error === null
		    && $result->failure === null
		    && !$result->isChanged()
		) {
			return;
		}

		$this->write($this->console->color('white', $result->path) . "\n");
		if ($result->failure !== null) {
			$this->write('          ' . $this->console->color('red', 'failure') . "  $result->failure\n");
		}

		if ($result->error !== null) {
			$this->write(sprintf("  %-7s %s    %s\n", $result->errorLine ?? '', $this->console->color('red', 'error'), $result->error));
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
				"  %-7s %s %s  %s%s\n",
				$position,
				$violation->severity === Severity::Error ? $this->console->color('red', 'error   ') : $this->console->color('yellow', 'warning '),
				$violation->message,
				$this->console->color('gray', "[$violation->ruleName]"),
				$marks ? '  (' . implode(', ', $marks) . ')' : '',
			));
		}

		foreach ($result->warnings as $warning) {
			$this->write('          ' . $this->console->color('yellow', 'warning') . "  $warning\n");
		}

		if ($this->diff && $result->isChanged()) {
			$this->write($this->colorDiff(Diff::unified($result->code, (string) $result->output, $result->path)));
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

		if ($failures = $result->countFailures()) {
			$line .= sprintf(', %s failed', self::plural($failures, 'file'));
		}

		$this->write($this->console->color($result->getExitCode() === 0 ? 'green' : 'red', "$line.") . "\n");
	}


	private function colorDiff(string $diff): string
	{
		return implode('', array_map(
			fn(string $line) => match ($line[0] ?? '') {
				'-' => $this->console->color('red', $line),
				'+' => $this->console->color('green', $line),
				'@' => $this->console->color('teal', $line),
				default => $line,
			},
			preg_split('~(?<=\n)~', $diff, -1, PREG_SPLIT_NO_EMPTY) ?: [],
		));
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
