<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function is_array, is_int, is_string;


/**
 * A count, `2`; where a range is taken, `[1, 2]`, `1–2` with an en dash or a hyphen and an open one `1+`; and a
 * word where the decision names one, `none`.
 */
final readonly class Count extends Domain
{
	public function __construct(
		public int $min = 0,
		public ?int $max = null,
		public bool $range = true,
		/** @var array<string, string> word => what it means */
		public array $words = [],
	) {
		if ($max !== null && $max < $min) {
			throw new \InvalidArgumentException("A count from $min cannot end at $max.");
		} elseif (isset($words['keep'])) {
			throw new \InvalidArgumentException('`keep` is no word of a count.');
		}
	}


	public function toArray(): array
	{
		return ['kind' => 'count', 'min' => $this->min, 'max' => $this->max, 'range' => $this->range, 'words' => $this->words];
	}


	public function findSoleValue(): mixed
	{
		return !$this->range && $this->words === [] && $this->min === $this->max ? $this->min : null;
	}


	public function describe(): string
	{
		$res = $this->max === null ? "a count from $this->min" : "a count from $this->min to $this->max";
		if ($this->range) {
			$res .= ", a range `1\u{2013}2`, an open one `1+`";
		}

		foreach ($this->words as $word => $meaning) {
			$res .= "; `$word`" . ($meaning === '' ? '' : " ($meaning)");
		}

		return $res;
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		$range = match (true) {
			is_int($raw) => [$raw, $raw],
			!$this->range => null,
			is_string($raw) && preg_match('~^(\d+)\s*[-\x{2013}]\s*(\d+)$~uD', $raw, $m) === 1 => self::fitsInteger($m[1]) && self::fitsInteger($m[2]) ? [(int) $m[1], (int) $m[2]] : null,
			is_string($raw) && preg_match('~^(\d+)\+$~D', $raw, $m) === 1 => self::fitsInteger($m[1]) ? [(int) $m[1], null] : null,
			is_array($raw) && array_keys($raw) === [0, 1] && is_int($raw[0]) && (is_int($raw[1]) || $raw[1] === null) => [$raw[0], $raw[1]],
			default => null,
		};

		if ($range !== null) {
			[$from, $to] = $range;
			if ($to !== null && $to < $from) {
				self::refuse($raw, $path, $this->listExpected(), $keep, 'whose end lies below its start');
			} elseif ($from < $this->min || ($this->max !== null && ($to ?? PHP_INT_MAX) > $this->max)) {
				self::refuse($raw, $path, $this->listExpected(), $keep, 'which lies outside ' . ($this->max === null ? "$this->min and more" : "$this->min to $this->max"));
			}

			return new Value($this, $range, $raw);

		} elseif (is_string($raw) && isset($this->words[$raw])) {
			return new Value($this, $raw, $raw);
		}

		self::refuse($raw, $path, $this->listExpected(), $keep);
	}


	/** Whether the digits make a number an integer holds. */
	private static function fitsInteger(string $digits): bool
	{
		return (string) (int) $digits === (ltrim($digits, '0') ?: '0');
	}


	/** @return list<string> */
	private function listExpected(): array
	{
		$expected = [$this->max === null ? "a count from `$this->min`" : "a count from `$this->min` to `$this->max`"];
		if ($this->range) {
			$expected[] = "a range such as `1\u{2013}2` or `1+`";
		}

		return [...$expected, ...array_map(fn($word) => "`$word`", array_keys($this->words))];
	}
}
