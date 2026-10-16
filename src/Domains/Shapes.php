<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_string;


/**
 * A shape shown by an example, `'foo($a, $b)'`, which a configuration writes and which is matched and never read, so
 * that an unknown one is an error naming the known ones; the rules read the word naming it, which no layer writes.
 * Never a list.
 */
final readonly class Shapes extends Domain
{
	public function __construct(
		/** @var array<string, array{string, string}>  word => the example and what it means */
		public array $shapes,
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

		return implode('; ', $shapes);
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
		$word = is_string($raw) ? array_find_key($this->shapes, fn(array $shape) => $shape[0] === $raw) : null;
		if ($word !== null) {
			return new Value($this, $word, $raw);
		}

		$expected = array_map(fn(array $shape) => '`' . self::quote($shape[0]) . '`', array_values($this->shapes));
		self::refuse($raw, $path, $expected, $keep, match (true) {
			is_string($raw) && isset($this->shapes[$raw]) => 'a word where the shape is written by its example',
			!is_string($raw) => 'which NEON read as no text; an example is written in quotes',
			default => '',
		});
	}
}
