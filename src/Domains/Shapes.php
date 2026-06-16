<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_string;


/**
 * A shape, named by a word and shown by an example, `compact` and `"foo($a, $b)"`: a configuration writes either, the
 * example being matched and never read, so that an unknown one is an error naming the known ones, and the value is
 * the word. Never a list.
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
		return count($this->shapes) === 1 ? array_key_first($this->shapes) : null;
	}


	public function describe(): string
	{
		$shapes = [];
		foreach ($this->shapes as $word => [$example, $meaning]) {
			$shapes[] = "$word \"$example\"" . ($meaning === '' ? '' : " ($meaning)");
		}

		return implode('; ', $shapes);
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		$word = !is_string($raw) ? null : (isset($this->shapes[$raw]) ? $raw : array_find_key($this->shapes, fn(array $shape) => $shape[0] === $raw));
		return $word !== null
			? new Value($this, $word, $raw)
			: self::refuse($raw, $path, array_map(
				fn(string $word, array $shape) => self::formatRaw($word) . ' (' . self::formatRaw("\"$shape[0]\"") . ')',
				array_keys($this->shapes),
				$this->shapes,
			), $keep);
	}
}
