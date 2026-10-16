<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_array, is_string;


/**
 * A shape shown by an example, `'foo($a, $b)'`, which a configuration writes and which is matched and never read, so
 * that an unknown one is an error naming the known ones; the rules read the word naming it, which no layer writes.
 * Where a tolerance is taken, a list of them, every shape in it passing and the first written where none matches.
 */
final readonly class Shapes extends Domain
{
	public function __construct(
		/** @var array<string, array{string, string}>  word => the example and what it means */
		public array $shapes,
		public bool $tolerance = false,
	) {
		if ($shapes === [] || isset($shapes['keep'])) {
			throw new \InvalidArgumentException('Shapes must be some, and `keep` is none of them.');
		}
	}


	public function toArray(): array
	{
		return [
			'kind' => 'shapes',
			'shapes' => array_map(fn(array $shape) => ['example' => $shape[0], 'meaning' => $shape[1]], $this->shapes),
			'tolerance' => $this->tolerance,
		];
	}


	public function findSoleValue(): mixed
	{
		return count($this->shapes) === 1 ? $this->shapes[array_key_first($this->shapes)][0] : null;
	}


	public function describe(): string
	{
		$shapes = [];
		foreach ($this->shapes as [$example, $meaning]) {
			$shapes[] = '`' . self::quote($example) . '`' . ($meaning === '' ? '' : " ($meaning)");
		}

		return implode('; ', $shapes)
			. ($this->tolerance ? '; a list of them, every one passing and the first written where none matches' : '');
	}


	/** The example of the shape the word names. */
	public function getExample(string $word): string
	{
		return $this->shapes[$word][0] ?? throw new \LogicException("Shape `$word` is unknown.");
	}


	/** The example as the configuration writes it, in single quotes. */
	public static function quote(string $example): string
	{
		return "'" . str_replace("'", "''", $example) . "'";
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		$word = $this->findWord($raw);
		if ($word !== null) {
			return new Value($this, [$word], $raw);

		} elseif ($this->tolerance && is_array($raw) && $raw !== [] && array_is_list($raw)) {
			$words = array_map(fn($example) => $this->findWord($example) ?? $this->refuseValue($example, $path, $keep), $raw);
			if (count(array_unique($words)) !== count($words)) {
				self::refuse($raw, $path, ['each shape once'], false, 'a shape given twice');
			}

			return new Value($this, $words, $raw);
		}

		$this->refuseValue($raw, $path, $keep);
	}


	private function findWord(mixed $example): ?string
	{
		return is_string($example) ? array_find_key($this->shapes, fn(array $shape) => $shape[0] === $example) : null;
	}


	private function refuseValue(mixed $raw, string $path, bool $keep): never
	{
		$expected = array_map(fn(array $shape) => '`' . self::quote($shape[0]) . '`', array_values($this->shapes));
		if ($this->tolerance) {
			$expected[] = 'a list of them';
		}

		self::refuse($raw, $path, $expected, $keep, match (true) {
			is_string($raw) && isset($this->shapes[$raw]) => 'a word where the shape is written by its example',
			!is_string($raw) => 'which NEON read as no text; an example is written in quotes',
			default => '',
		});
	}
}
