<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_array, is_string;


/**
 * A list of names the project brings, `[password, passwd]`, of regular expressions, `['~^__~']`, of the words
 * of a set or an order, `[traitUse, constant]`, or of words and regular expressions, `[imports, '~^\s*throw ~']`.
 * A list replaces the one below it, the default included. An empty set has no item, an empty order is none, and
 * `keep` is never an item.
 */
final readonly class Names extends Domain
{
	public function __construct(
		/** @var ?array<string, string> word => what it means, for a set or an order of words; null for free names or patterns */
		public ?array $words = null,
		/** the names are regular expressions, or with words, every name that is not one of them */
		public bool $regularExpressions = false,
		/** the order of the list matters */
		public bool $ordered = false,
		/** whether the list may be empty, which a set a requirement acts on may not */
		public bool $empty = true,
	) {
		if ($words === []) {
			throw new \InvalidArgumentException('The words must be some.');
		}
	}


	public function toArray(): array
	{
		return [
			'kind' => 'names',
			'words' => $this->words,
			'regularExpressions' => $this->regularExpressions,
			'ordered' => $this->ordered,
			'empty' => $this->empty && !$this->ordered,
		];
	}


	public function describe(): string
	{
		if ($this->words === null) {
			return $this->regularExpressions ? 'a list of regular expressions' : 'a list of names';
		}

		$words = [];
		foreach ($this->words as $word => $meaning) {
			$words[] = "`$word`" . ($meaning === '' ? '' : " ($meaning)");
		}

		return ($this->ordered ? 'an order of ' : 'a list of ')
			. implode('; ', $words)
			. ($this->regularExpressions ? '; regular expressions' : '');
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		if (!is_array($raw) || !array_is_list($raw)) {
			self::refuse($raw, $path, [$this->describe()], $keep);
		} elseif ($raw === [] && ($this->ordered || !$this->empty)) {
			self::refuse(
				$raw,
				$path,
				[$this->describe()],
				$keep,
				$this->ordered ? 'an order of nothing' : 'which leaves the decision nothing to act on',
			);
		}

		if (count(array_keys($raw, self::Spread, true)) > 1) {
			self::refuse($raw, $path, [$this->describe()], $keep, '`...` standing for the list below it once only');
		}

		foreach ($raw as $name) {
			if ($name === self::Spread) {
				continue;
			} elseif (!is_string($name) || $name === '') {
				self::refuse($raw, $path, [$this->describe()], $keep, 'which holds no name');
			} elseif ($name === 'keep') {
				self::refuse($raw, $path, [$this->describe()], $keep, '`keep` being no item of a list');
			} elseif (isset($this->words[$name])) {
				continue;
			} elseif ($this->words !== null && !$this->regularExpressions) {
				self::refuse($name, $path, array_map(fn($word) => "`$word`", array_keys($this->words)), false);
			} elseif ($this->regularExpressions && @preg_match($name, '') === false) { // @ the error is the answer
				self::refuse(
					$name,
					$path,
					[...array_map(fn($word) => "`$word`", array_keys($this->words ?? [])), 'a regular expression'],
					false,
				);
			}
		}

		return count(array_unique($raw)) === count($raw)
			? new Value($this, $raw, $raw)
			: self::refuse($raw, $path, ['each name once'], false, 'a name given twice');
	}


	public function merge(Value $below, Value $above, string $path = ''): Value
	{
		if ($above->isKept() || !in_array(self::Spread, $above->getNames(), true)) {
			return $above;
		}

		$names = self::spread($below->isKept() ? [] : $below->getNames(), $above->getNames(), !$this->ordered, $path);
		return new Value($this, $names, $above->raw, $above->origin);
	}
}
