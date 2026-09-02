<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{FileResult, Risk, Severity, Violation};
use function is_array;


/**
 * The result of a file as a worker sends it to the parent: the output only where it differs from the code, which
 * the parent has, the output and the path base64-encoded since neither need be UTF-8.
 * @internal
 */
final class WorkerCodec
{
	/** @return array<string, mixed> */
	public static function encode(FileResult $result): array
	{
		return [
			'path' => base64_encode($result->path),
			'output' => $result->output === $result->code ? true : ($result->output === null ? null : base64_encode($result->output)),
			'violations' => array_map(self::encodeViolation(...), $result->violations),
			'warnings' => $result->warnings,
			'syntaxError' => $result->syntaxError,
			'syntaxErrorLine' => $result->syntaxErrorLine,
			'passes' => $result->passes,
			'failure' => $result->failure,
			'failureDocs' => $result->failureDocs,
			'baselined' => $result->baselined,
			'remaining' => array_map(self::encodeViolation(...), $result->remaining),
			'written' => $result->written,
			'cached' => $result->cached,
		];
	}


	/**
	 * @param  array<string, mixed>  $data  as `encode()` made it
	 * @param  string  $code  the content the parent handed the worker
	 */
	public static function decode(array $data, string $code): FileResult
	{
		$output = $data['output'];
		return new FileResult(
			path: (string) base64_decode((string) $data['path'], strict: true),
			code: $code,
			output: $output === true ? $code : ($output === null ? null : (string) base64_decode((string) $output, strict: true)),
			violations: self::decodeViolations($data['violations']),
			warnings: is_array($data['warnings']) ? array_values(array_map(strval(...), $data['warnings'])) : [],
			syntaxError: $data['syntaxError'] === null ? null : (string) $data['syntaxError'],
			syntaxErrorLine: $data['syntaxErrorLine'] === null ? null : (int) $data['syntaxErrorLine'],
			passes: (int) $data['passes'],
			failure: $data['failure'] === null ? null : (string) $data['failure'],
			failureDocs: $data['failureDocs'] === null ? null : (string) $data['failureDocs'],
			baselined: is_array($data['baselined']) ? array_values(array_map(strval(...), $data['baselined'])) : [],
			remaining: self::decodeViolations($data['remaining']),
			written: (bool) $data['written'],
			cached: (bool) $data['cached'],
		);
	}


	/** @return array<string, mixed> */
	private static function encodeViolation(Violation $violation): array
	{
		return [
			'decision' => $violation->decision,
			'message' => $violation->message,
			'line' => $violation->line,
			'column' => $violation->column,
			'severity' => $violation->severity->value,
			'fingerprint' => $violation->fingerprint,
			'risk' => $violation->risk?->value,
			'refused' => $violation->refused,
			'because' => $violation->because,
			'derivedFrom' => $violation->derivedFrom,
		];
	}


	/** @return list<Violation> */
	private static function decodeViolations(mixed $data): array
	{
		return array_values(array_map(fn(array $item) => new Violation(
			(string) $item['decision'],
			(string) $item['message'],
			(int) $item['line'],
			$item['column'] === null ? null : (int) $item['column'],
			Severity::from((string) $item['severity']),
			(string) $item['fingerprint'],
			$item['risk'] === null ? null : Risk::from((string) $item['risk']),
			(bool) $item['refused'],
			$item['because'] === null ? null : (string) $item['because'],
			$item['derivedFrom'] === null ? null : (string) $item['derivedFrom'],
		), is_array($data) ? $data : []));
	}
}
