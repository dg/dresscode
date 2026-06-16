<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_array, is_string;


/**
 * A list of names the project brings, `[password, passwd]`, of regular expressions, `['~^__~']`, or of the words
 * of a set or an order, `[traitUse, constant]`. A list replaces the one below it, the default included.
 */
final readonly class Names extends Domain
{
	public function __construct(
		/** @var ?array<string, string> word => what it means, for a set or an order of words; null for free names or patterns */
		public ?array $words = null,
		/** the names are regular expressions */
		public bool $regularExpressions = false,
		/** the order of the list matters */
		public bool $ordered = false,
	) {
		if ($words !== null && ($words === [] || $regularExpressions)) {
			throw new \InvalidArgumentException('The words must be some, and they are no regular expressions.');
		}
	}


	public function toArray(): array
	{
		return ['kind' => 'names', 'words' => $this->words, 'regularExpressions' => $this->regularExpressions, 'ordered' => $this->ordered];
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

		return ($this->ordered ? 'an order of ' : 'a list of ') . implode('; ', $words);
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		if (!is_array($raw) || !array_is_list($raw)) {
			self::refuse($raw, $path, [$this->describe()], $keep);
		}

		foreach ($raw as $name) {
			if (!is_string($name) || $name === '') {
				self::refuse($raw, $path, [$this->describe()], $keep, 'which holds no name');
			} elseif ($this->words !== null && !isset($this->words[$name])) {
				self::refuse($name, $path, array_map(fn($word) => "`$word`", array_keys($this->words)), false);
			} elseif ($this->regularExpressions && @preg_match($name, '') === false) { // @ the error is the answer
				self::refuse($name, $path, ['a regular expression'], false);
			}
		}

		return count(array_unique($raw)) === count($raw)
			? new Value($this, $raw, $raw)
			: self::refuse($raw, $path, ['each name once'], false, 'a name given twice');
	}
}
