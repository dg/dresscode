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
 * the parent has, base64-encoded since it need not be UTF-8.
 * @internal
 */
final class WorkerCodec
{
	/** @return array<string, mixed> */
	public static function encode(FileResult $result): array
	{
		return [
			'path' => $result->path,
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
			(string) $data['path'],
			$code,
			$output === true ? $code : ($output === null ? null : (string) base64_decode((string) $output, strict: true)),
			self::decodeViolations($data['violations']),
			is_array($data['warnings']) ? array_values(array_map(strval(...), $data['warnings'])) : [],
			$data['syntaxError'] === null ? null : (string) $data['syntaxError'],
			$data['syntaxErrorLine'] === null ? null : (int) $data['syntaxErrorLine'],
			(int) $data['passes'],
			$data['failure'] === null ? null : (string) $data['failure'],
			$data['failureDocs'] === null ? null : (string) $data['failureDocs'],
			is_array($data['baselined']) ? array_values(array_map(strval(...), $data['baselined'])) : [],
			self::decodeViolations($data['remaining']),
			(bool) $data['written'],
			(bool) $data['cached'],
		);
	}


	/** @return array<string, mixed> */
	private static function encodeViolation(Violation $violation): array
	{
		return [
			'rule' => $violation->ruleName,
			'message' => $violation->message,
			'line' => $violation->line,
			'column' => $violation->column,
			'severity' => $violation->severity->name,
			'fingerprint' => $violation->fingerprint,
			'risk' => $violation->risk?->name,
			'refused' => $violation->refused,
			'because' => $violation->because,
			'derivedFrom' => $violation->derivedFrom,
		];
	}


	/** @return list<Violation> */
	private static function decodeViolations(mixed $data): array
	{
		return array_values(array_map(fn(array $item) => new Violation(
			(string) $item['rule'],
			(string) $item['message'],
			(int) $item['line'],
			$item['column'] === null ? null : (int) $item['column'],
			Severity::{$item['severity']},
			(string) $item['fingerprint'],
			$item['risk'] === null ? null : Risk::{$item['risk']},
			(bool) $item['refused'],
			$item['because'] === null ? null : (string) $item['because'],
			$item['derivedFrom'] === null ? null : (string) $item['derivedFrom'],
		), is_array($data) ? $data : []));
	}
}
