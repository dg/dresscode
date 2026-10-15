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
 * shape in it passing and the first written where none matches. A state of one word may take `warning` too, which
 * is that word with its violations only warning.
 */
final readonly class Words extends Domain
{
	private const Warning = 'warning';


	public function __construct(
		/** @var array<string, string> word => what it means */
		public array $words,
		public bool $tolerance = false,
		/** `warning` stands for the one word, its violations only warning */
		public bool $warning = false,
		/** @var array<string, string> word => the word it passes too, which a list therefore never names after it */
		public array $passes = [],
	) {
		if ($words === [] || isset($words['keep']) || isset($words[self::Warning])) {
			throw new \InvalidArgumentException('Words must be some, and neither `keep` nor `warning` is one of them.');
		} elseif ($warning && (count($words) !== 1 || $tolerance)) {
			throw new \InvalidArgumentException('Only a single word without a tolerance may take `warning`.');
		}
	}


	public function toArray(): array
	{
		return [
			'kind' => 'words',
			'words' => $this->getWords(),
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
		foreach ($this->getWords() as $word => $meaning) {
			$words[] = "`$word`" . ($meaning === '' ? '' : " ($meaning)");
		}

		return implode('; ', $words)
			. ($this->tolerance ? '; a list of them, every one passing and the first written where none matches' : '');
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		if (is_string($raw) && isset($this->words[$raw])) {
			return new Value($this, [$raw], $raw);

		} elseif ($raw === self::Warning && $this->warning) {
			return new Value($this, [array_key_first($this->words)], $raw);

		} elseif ($this->tolerance && is_array($raw) && $raw !== [] && array_is_list($raw)) {
			foreach ($raw as $word) {
				if (!is_string($word) || !isset($this->words[$word])) {
					$this->refuseValue($word, $path, $keep);
				}
			}

			if (count(array_unique($raw)) !== count($raw)) {
				self::refuse($raw, $path, ['each word once'], false, 'a word given twice');
			}

			foreach ($raw as $i => $word) {
				$passed = $this->passes[$word] ?? null;
				if ($passed !== null && in_array($passed, array_slice($raw, $i + 1), true)) {
					self::refuse($raw, $path, ["the list without `$passed`"], false, "`$word` passing `$passed` too");
				}
			}

			return new Value($this, $raw, $raw);
		}

		$this->refuseValue($raw, $path, $keep);
	}


	/** Whether the value is the word `warning` stands for, its violations only warning. */
	public function isWarning(Value $value): bool
	{
		return $this->warning && $value->raw === self::Warning;
	}


	/** @return array<string, string> word => what it means, `warning` included */
	private function getWords(): array
	{
		return $this->warning
			? $this->words + [self::Warning => 'as `' . array_key_first($this->words) . '`, its violations only warning']
			: $this->words;
	}


	private function refuseValue(mixed $raw, string $path, bool $keep): never
	{
		$expected = array_map(fn($word) => "`$word`", array_keys($this->getWords()));
		if ($this->tolerance) {
			$expected[] = 'a list of them';
		}

		self::refuse($raw, $path, $expected, $keep);
	}
}
