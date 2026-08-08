<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\FileResult;
use DressCode\Reporter;
use DressCode\RunResult;
use DressCode\Violation;
use function count;


/**
 * Machine-readable output: files with violations, syntax errors or changes, and a summary. A file is written
 * as soon as it is reported, so that a run over a large tree holds nothing back until the end.
 */
final class JsonReporter implements Reporter
{
	private const Flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

	/** @var resource */
	private $stream;

	private int $written = 0;


	/** @param ?resource $stream */
	public function __construct($stream = null)
	{
		$this->stream = $stream ?? STDOUT;
	}


	public function start(int $fileCount, bool $fix): void
	{
		$this->written = 0;
		fwrite($this->stream, "{\n    \"files\": [");
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

		$file = [
			'path' => $result->path,
			'violations' => array_map(self::violation(...), $result->violations),
			'warnings' => $result->warnings,
			'error' => $result->error === null ? null : ['message' => $result->error, 'line' => $result->errorLine],
			'failure' => $result->failure,
			'changed' => $result->isChanged(),
			'written' => $result->written,
		];
		$json = (string) preg_replace('~^~m', '        ', json_encode($file, self::Flags));
		fwrite($this->stream, ($this->written++ === 0 ? "\n" : ",\n") . $json);
	}


	public function finish(RunResult $result): void
	{
		$rest = [
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
		// the rest of the document, its outer braces stripped, continues the one opened in start()
		$json = substr(json_encode($rest, self::Flags), 2, -2);
		fwrite($this->stream, ($this->written === 0 ? '' : "\n    ") . "],\n" . $json . "\n}\n");
	}


	/** @return array<string, mixed> */
	private static function violation(Violation $violation): array
	{
		return $violation->toArray();
	}
}
