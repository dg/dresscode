<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\Engine\Diff;
use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Severity;
use DressCode\Violation;
use Nette\CommandLine\Console;
use function count, strlen;
use const DIRECTORY_SEPARATOR;


/**
 * Human-readable listing: what the user has to deal with, grouped by file and optionally with a diff of the
 * fixes, then what the fixer changed, then the verdict. A fixed violation is counted, not listed.
 */
final class ConsoleReporter implements Reporter
{
	/** the message column follows the longest message but never grows past this */
	private const MessageWidth = 100;

	/** a run shorter than this is not worth timing */
	private const LongRun = 1.0;

	/** @var resource */
	private $stream;
	private Console $console;

	/** the bare format has no room for it */
	private readonly bool $diff;

	private bool $fix = false;
	private int $fileCount = 0;
	private float $started = 0.0;

	/** some file was listed, so the verdict needs a blank line above it */
	private bool $listed = false;

	/** the file before this one printed lines under its name and wants a blank line after it */
	private bool $separate = false;


	/**
	 * @param ?resource $stream
	 * @param ?Console $console  colors; plain output when omitted
	 * @param string $root  the paths of the results are relative to it
	 * @param string $cwd  a file under it is reported relative to it, the others absolutely
	 * @param bool $bare  only what is left to the user and which files were rewritten, nothing else
	 */
	public function __construct(
		$stream = null,
		bool $diff = false,
		?Console $console = null,
		private readonly string $root = '',
		private readonly string $cwd = '',
		private readonly bool $bare = false,
	) {
		$this->stream = $stream ?? STDOUT;
		if ($console === null) {
			$console = new Console;
			$console->useColors(false);
		}

		$this->console = $console;
		$this->diff = $diff && !$bare;
	}


	public function start(int $fileCount, bool $fix): void
	{
		$this->fix = $fix;
		$this->fileCount = $fileCount;
		$this->started = microtime(as_float: true);
		$this->listed = $this->separate = false;
	}


	public function reportFile(FileResult $result): void
	{
		$violations = $this->fix && $this->bare ? $result->getUnfixedViolations() : $result->violations;
		// nothing among the listed violations says the file was touched, so the file has to say it itself
		$rewritten = $this->fix && $result->isChanged()
			&& !array_filter($violations, fn(Violation $v) => $v->fixable);
		if (
			!$violations
			&& !$rewritten
			&& !$result->warnings
			&& $result->error === null
			&& $result->failure === null
		) {
			return;
		}

		if ($this->separate) {
			$this->write("\n");
		}

		$this->listed = true;
		$this->write($this->console->color('white', $this->formatPath($result->path))
			. ($rewritten ? $this->console->color('gray', '  rewritten') : '') . "\n");
		if ($result->failure !== null) {
			$this->write('  ' . $this->console->color('red', $result->failure) . "\n");
		}

		if ($result->error !== null) {
			$position = $result->errorLine === null ? '' : "$result->errorLine  ";
			$this->write('  ' . $this->console->color('red', $position . $result->error) . "\n");
		}

		$this->writeViolations($violations);
		foreach ($result->warnings as $warning) {
			$this->write('  ' . $this->console->color('yellow', $warning) . "\n");
		}

		if ($this->diff && $result->isChanged()) {
			$this->write($this->colorDiff(Diff::unified($result->code, (string) $result->output, $result->path)));
		}

		// a file with nothing below its name stays a single line, the others are set apart
		$this->separate = (bool) ($violations || $result->warnings || $result->error !== null
			|| $result->failure !== null || ($this->diff && $result->isChanged()));
	}


	/**
	 * A fix run marks what it fixed; the position of such a violation belongs to the file as it was read,
	 * because the fixes have moved the lines since.
	 * @param list<Violation> $violations
	 */
	private function writeViolations(array $violations): void
	{
		if (!$violations) {
			return;
		}

		$positions = array_map(self::formatPosition(...), $violations);
		$positionWidth = max(array_map(strlen(...), $positions));
		$messageWidth = min(self::MessageWidth, max(array_map(fn(Violation $v) => strlen($v->message), $violations)));
		$stateWidth = max(array_map(fn(Violation $v) => strlen(self::formatState($v, $this->fix)), $violations));

		foreach ($violations as $i => $violation) {
			$state = self::formatState($violation, $this->fix);
			$this->write(sprintf(
				"  %s  %s  %s  %s\n",
				$this->console->color(match ($state) {
					'fixed' => 'green',
					'warning' => 'olive',
					default => 'maroon',
				}, str_pad($state, $stateWidth)),
				$this->console->color('gray', str_pad($positions[$i], $positionWidth, ' ', STR_PAD_LEFT)),
				str_pad($violation->message, $messageWidth),
				$this->console->color('gray', self::formatRule($violation->ruleName)),
			));
		}
	}


	public function finish(RunResult $result): void
	{
		if ($this->bare || $this->fileCount === 0) { // an empty scope is the header's business, not a verdict
			return;
		}

		if ($this->listed) {
			$this->write("\n");
		}

		foreach ($result->warnings as $warning) {
			$this->write($this->console->color('yellow', "Warning: $warning") . "\n\n");
		}

		$this->write($this->console->color(
			$result->getExitCode() === 0 ? 'white/green' : 'white/red',
			$this->formatVerdict($result),
		) . "\n");
	}


	/** The state of the run, then every count with the noun it counts, then the scope it all happened in. */
	private function formatVerdict(RunResult $result): string
	{
		$fixed = $this->fix ? $result->countFixable() : 0;
		$remaining = $result->countViolations() - $fixed;
		$failures = $result->countFailures();
		$affected = count(array_filter(
			$result->files,
			fn(FileResult $f) => $f->violations || $f->error !== null || $f->failure !== null,
		));

		$parts = array_filter([
			$fixed ? self::plural($fixed, 'violation') . ' fixed' : null,
			$remaining ? ($fixed ? "$remaining remaining" : self::plural($remaining, 'violation')) : null,
			!$this->fix && $result->countFixable() ? $result->countFixable() . ' of them fixable' : null,
			$result->countErrors() ? self::plural($result->countErrors(), 'file') . ' with syntax errors' : null,
			$failures ? self::plural($failures, 'file') . ' with failing rules' : null,
			$result->baselined ? self::plural($result->baselined, 'violation') . ' in the baseline' : null,
		]);

		$state = match (true) {
			$failures > 0 => 'FAILED',
			$fixed > 0 => 'FIXED',
			$remaining > 0 || $result->countErrors() > 0 => 'FOUND',
			default => 'OK',
		};
		$scope = $affected > 0 && $affected < $this->fileCount
			? sprintf('%d of %s', $affected, self::plural($this->fileCount, 'file'))
			: self::plural($this->fileCount, 'file');
		$elapsed = microtime(as_float: true) - $this->started;

		return "$state  " . ($parts ? implode(', ', $parts) : 'no violations') . " in $scope"
			. ($elapsed < self::LongRun ? '' : sprintf(', %.1f s', $elapsed));
	}


	/**
	 * Relative to the working directory when the file lies under it, absolute otherwise, so that the path
	 * can be clicked and copied wherever the run was started from.
	 */
	private function formatPath(string $path): string
	{
		$path = $this->root === '' ? $path : "$this->root/$path";
		if ($this->cwd !== '' && str_starts_with($path, "$this->cwd/")) {
			$path = substr($path, strlen($this->cwd) + 1);
		}

		return str_replace('/', DIRECTORY_SEPARATOR, $path);
	}


	/** What the run made of the violation: a fix run fixed it, otherwise it is left to the user. */
	private static function formatState(Violation $violation, bool $fix): string
	{
		return match (true) {
			$fix && $violation->fixable => 'fixed',
			$violation->severity === Severity::Warning => 'warning',
			default => 'error',
		};
	}


	private static function formatPosition(Violation $violation): string
	{
		return $violation->line . ($violation->column === null ? '' : ":$violation->column");
	}


	/** The namespace of the built-in rules would be noise repeated on every line. */
	private static function formatRule(string $name): string
	{
		return str_starts_with($name, 'dresscode/') ? substr($name, strlen('dresscode/')) : $name;
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
