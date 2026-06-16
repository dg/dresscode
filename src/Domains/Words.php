<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_array, is_string;


/**
 * A word saying the state, `forbidden`, `imported`, `nextLine`; where a tolerance is taken, a list of them, every
 * shape in it passing and the first written where none matches.
 */
final readonly class Words extends Domain
{
	public function __construct(
		/** @var array<string, string> word => what it means */
		public array $words,
		public bool $tolerance = false,
	) {
		if ($words === [] || isset($words['keep'])) {
			throw new \InvalidArgumentException('Words must be some, and `keep` is none of them.');
		}
	}


	public function toArray(): array
	{
		return [
			'kind' => 'words',
			'words' => $this->words,
			'tolerance' => $this->tolerance,
		];
	}


	public function findSoleValue(): mixed
	{
		return count($this->words) === 1 ? array_key_first($this->words) : null;
	}


	public function describe(): string
	{
		$words = [];
		foreach ($this->words as $word => $meaning) {
			$words[] = "`$word`" . ($meaning === '' ? '' : " ($meaning)");
		}

		return implode('; ', $words) . ($this->tolerance ? '; a list of them, every one passing and the first written where none matches' : '');
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		if (is_string($raw) && isset($this->words[$raw])) {
			return new Value($this, [$raw], $raw);

		} elseif ($this->tolerance && is_array($raw) && $raw !== [] && array_is_list($raw)) {
			foreach ($raw as $word) {
				if (!is_string($word) || !isset($this->words[$word])) {
					$this->refuseValue($word, $path, $keep);
				}
			}

			if (count(array_unique($raw)) !== count($raw)) {
				self::refuse($raw, $path, ['each word once'], false, 'a word given twice');
			}

			return new Value($this, $raw, $raw);
		}

		$this->refuseValue($raw, $path, $keep);
	}


	private function refuseValue(mixed $raw, string $path, bool $keep): never
	{
		$expected = array_map(fn($word) => "`$word`", array_keys($this->words));
		if ($this->tolerance) {
			$expected[] = 'a list of them';
		}

		self::refuse($raw, $path, $expected, $keep);
	}
}
