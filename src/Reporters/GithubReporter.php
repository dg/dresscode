<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\FileResult;
use DressCode\Helpers;
use DressCode\Reporter;
use DressCode\RunResult;
use DressCode\Severity;
use function sprintf, strlen;


/**
 * Workflow commands of GitHub Actions: every violation becomes an annotation shown at its line
 * in the pull request. A fix run annotates only what it could not fix, because the lines of the
 * rest have already moved.
 */
final class GithubReporter implements Reporter
{
	/** @var resource */
	private $stream;
	private bool $fix = false;
	private int $fileCount = 0;


	/**
	 * @param ?resource $stream
	 */
	public function __construct(
		$stream = null,
		/** the paths of the results are relative to it */
		private readonly string $root = '',
		/** the checkout the annotations are addressed from */
		private readonly ?string $workspace = null,
	) {
		$this->stream = $stream ?? STDOUT;
	}


	public function start(int $fileCount, bool $fix): void
	{
		$this->fix = $fix;
		$this->fileCount = $fileCount;
	}


	public function reportFile(FileResult $result): void
	{
		$path = $this->formatPath($result->path);
		if ($result->failure !== null) {
			$this->annotate('error', $path, 1, null, 'dresscode', $result->failure);
		}

		if ($result->error !== null) {
			$this->annotate('error', $path, $result->errorLine ?? 1, null, 'syntax error', $result->error);
		}

		foreach ($result->warnings as $warning) {
			$this->annotate('warning', $path, 1, null, 'dresscode', $warning);
		}

		foreach ($this->fix ? $result->getUnfixedViolations() : $result->violations as $violation) {
			$this->annotate(
				$violation->severity === Severity::Error ? 'error' : 'warning',
				$path,
				$violation->line,
				$violation->column,
				$violation->ruleName,
				$violation->message,
			);
		}
	}


	public function finish(RunResult $result): void
	{
		foreach ($result->warnings as $warning) {
			$this->write('::warning title=dresscode::' . self::escapeData($warning) . "\n");
		}

		$violations = $result->countViolations() - ($this->fix ? $result->countFixable() : 0);
		$this->write(sprintf(
			"%d violation%s in %d file%s\n",
			$violations,
			$violations === 1 ? '' : 's',
			$this->fileCount,
			$this->fileCount === 1 ? '' : 's',
		));
	}


	private function annotate(string $type, string $path, int $line, ?int $column, string $title, string $message): void
	{
		$this->write(sprintf(
			"::%s file=%s,line=%d%s,title=%s::%s\n",
			$type,
			self::escapeProperty($path),
			$line,
			$column === null ? '' : ',col=' . $column,
			self::escapeProperty($title),
			self::escapeData($message),
		));
	}


	/** Relative to the checkout, which is where GitHub looks the file up. */
	private function formatPath(string $path): string
	{
		$path = $this->root === '' ? $path : "$this->root/$path";
		$workspace = $this->workspace === null ? null : Helpers::canonicalizePath($this->workspace);
		return $workspace !== null && str_starts_with($path, "$workspace/")
			? substr($path, strlen($workspace) + 1)
			: $path;
	}


	private static function escapeData(string $text): string
	{
		return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);
	}


	private static function escapeProperty(string $text): string
	{
		return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $text);
	}


	private function write(string $text): void
	{
		fwrite($this->stream, $text);
	}
}
