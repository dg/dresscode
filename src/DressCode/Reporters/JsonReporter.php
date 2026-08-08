<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Severity;
use DressCode\Violation;
use function count;


/**
 * Machine-readable output: files with violations, syntax errors or changes, and a summary.
 */
final class JsonReporter implements Reporter
{
	/** @var resource */
	private $stream;

	/** @var list<array<string, mixed>> */
	private array $files = [];


	/** @param ?resource $stream */
	public function __construct($stream = null)
	{
		$this->stream = $stream ?? STDOUT;
	}


	public function start(int $fileCount, bool $fix): void
	{
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

		$this->files[] = [
			'path' => $result->path,
			'violations' => array_map(self::violation(...), $result->violations),
			'warnings' => $result->warnings,
			'error' => $result->error === null ? null : ['message' => $result->error, 'line' => $result->errorLine],
			'failure' => $result->failure,
			'changed' => $result->isChanged(),
			'written' => $result->written,
		];
	}


	public function finish(RunResult $result): void
	{
		$data = [
			'files' => $this->files,
			'summary' => [
				'files' => count($result->files),
				'violations' => $result->countViolations(),
				'fixable' => $result->countFixable(),
				'changedFiles' => $result->countChangedFiles(),
				'errors' => $result->countErrors(),
				'failures' => $result->countFailures(),
				'baselined' => $result->baselined,
			],
			'warnings' => $result->warnings,
		];
		fwrite($this->stream, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
	}


	/** @return array<string, mixed> */
	private static function violation(Violation $violation): array
	{
		return [
			'rule' => $violation->ruleName,
			'message' => $violation->message,
			'line' => $violation->line,
			'column' => $violation->column,
			'severity' => $violation->severity === Severity::Error ? 'error' : 'warning',
			'fixable' => $violation->fixable,
			'followUp' => $violation->followUp,
			'fingerprint' => $violation->fingerprint,
		];
	}
}
