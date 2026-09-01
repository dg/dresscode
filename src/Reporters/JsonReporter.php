<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\{FileResult, Reporter, RunResult, Violation};
use function count;
use const JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE;


/**
 * Machine-readable output: files with violations, warnings, errors, failures or changes, and a summary. A file is written
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
			'violations' => array_map(fn(Violation $v) => $v->toArray(), $result->violations),
			'remaining' => array_map(fn(Violation $v) => $v->toArray(), $result->remaining),
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
				'remaining' => $result->countLeftAfterFix(),
				'riskyDeferred' => $result->countRiskyDeferred(),
				'changedFiles' => $result->countChangedFiles(),
				'syntaxErrors' => $result->countSyntaxErrors(),
				'failures' => $result->countFailures(),
				'baselined' => $result->baselined,
			],
			'warnings' => $result->warnings,
		];
		// the rest of the document, its outer braces stripped, continues the one opened in start()
		$json = substr(json_encode($rest, self::Flags), 2, -2);
		fwrite($this->stream, ($this->written === 0 ? '' : "\n    ") . "],\n" . $json . "\n}\n");
	}
}
