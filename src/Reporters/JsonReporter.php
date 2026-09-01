<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter, Violation};
use function count;
use const JSON_INVALID_UTF8_SUBSTITUTE, JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE;


/**
 * Machine-readable output: the version of the format, the mode and the root of the run, the files with violations,
 * warnings, syntax errors, failures or changes, and a summary. A file is written as soon as it is reported, so that
 * a run over a large tree holds nothing back until the end. Its keys are camelCase, the values of the code as they are.
 * @internal
 */
final class JsonReporter implements Reporter
{
	private const Version = 1;

	private const Flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

	/** @var resource */
	private $stream;

	private int $written = 0;


	/** @param ?resource $stream */
	public function __construct($stream = null)
	{
		$this->stream = $stream ?? STDOUT;
	}


	public function start(RunInfo $run): void
	{
		$this->written = 0;
		$head = ['version' => self::Version, 'mode' => $run->fix ? 'fix' : 'check', 'root' => $run->root];
		// the document stays open for the files, finish() closes it
		fwrite($this->stream, "{\n" . self::encodeMembers($head) . ",\n    \"files\": [");
	}


	public function reportFile(FileResult $result): void
	{
		if (
			!$result->violations
			&& !$result->warnings
			&& $result->syntaxError === null
			&& $result->failure === null
			&& !$result->changed
		) {
			return;
		}

		$file = [
			'path' => $result->path,
			'violations' => array_map(self::formatViolation(...), $result->violations),
			'remaining' => array_map(self::formatViolation(...), $result->remaining),
			'warnings' => $result->warnings,
			'syntaxError' => $result->syntaxError === null ? null : ['message' => $result->syntaxError, 'line' => $result->syntaxErrorLine],
			'failure' => $result->failure === null ? null : ['message' => $result->failure, 'docs' => $result->failureDocs],
			'changed' => $result->changed,
			'written' => $result->written,
		];
		$json = (string) preg_replace('~^~m', '        ', json_encode($file, self::Flags));
		fwrite($this->stream, ($this->written++ === 0 ? "\n" : ",\n") . $json);
	}


	/** @return array<string, mixed> */
	private static function formatViolation(Violation $violation): array
	{
		return [
			'decision' => $violation->decision,
			'message' => $violation->message,
			'line' => $violation->line,
			'column' => $violation->column,
			'severity' => $violation->severity->value,
			'risk' => $violation->risk?->value,
			'refused' => $violation->refused,
			'because' => $violation->because,
			'fingerprint' => $violation->fingerprint,
			'derivedFrom' => $violation->derivedFrom,
		];
	}


	public function finish(RunResult $result): void
	{
		$rest = [
			'summary' => [
				'files' => count($result->files),
				'violations' => $result->countViolations(),
				'remaining' => $result->countRemaining(),
				'refused' => $result->countRefused(),
				'changedFiles' => $result->countChangedFiles(),
				'syntaxErrors' => $result->countSyntaxErrors(),
				'failures' => $result->countFailures(),
				'baselined' => $result->baselined,
				'exitCode' => $result->getExitCode(),
			],
			'warnings' => $result->warnings,
		];
		fwrite($this->stream, ($this->written === 0 ? '' : "\n    ") . "],\n" . self::encodeMembers($rest) . "\n}\n");
	}


	/**
	 * The members of an object of the document, one a line, as the pretty print indents them.
	 * @param  array<string, mixed>  $members
	 */
	private static function encodeMembers(array $members): string
	{
		return implode(",\n", array_map(
			fn(string $key, mixed $value) => '    ' . json_encode($key, self::Flags) . ': ' . str_replace("\n", "\n    ", json_encode($value, self::Flags)),
			array_keys($members),
			$members,
		));
	}
}
