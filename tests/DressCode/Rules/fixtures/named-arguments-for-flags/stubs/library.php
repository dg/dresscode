<?php

namespace Acme;

class Json
{
	public const PRETTY = 1;
	public const ESCAPE_UNICODE = 2;
	public const OTHER = 4;


	public static function encode(mixed $value, bool|int $pretty = false, bool $asciiSafe = false): string
	{
		return '';
	}
}


class PrettyJson extends Json
{
	public function export(mixed $value): string
	{
		return self::encode($value, self::PRETTY);
	}
}


class Strings
{
	public static function match(
		string $subject,
		string $pattern,
		bool|int $captureOffset = false,
		int $offset = 0,
		bool $unmatchedAsNull = false,
	): ?array
	{
		return null;
	}
}
