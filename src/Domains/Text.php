<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function count, is_array, is_string;


/**
 * A text of the project's own, which the rule writes as it is given (`break omitted`), where no list of words or
 * shapes could hold what projects write; where a tolerance is taken, a list of them, every one passing and the first
 * written.
 */
final readonly class Text extends Domain
{
	public function __construct(
		public bool $tolerance = false,
	) {
	}


	public function toArray(): array
	{
		return ['kind' => 'text', 'tolerance' => $this->tolerance];
	}


	public function describe(): string
	{
		return 'a text' . ($this->tolerance ? ', or a list of them, every one passing and the first written' : '');
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		$texts = $this->tolerance && is_array($raw) && $raw !== [] && array_is_list($raw) ? $raw : [$raw];
		foreach ($texts as $text) {
			if (!is_string($text) || trim($text) === '') {
				self::refuse($raw, $path, $this->tolerance ? ['a text', 'a list of them'] : ['a text'], $keep);
			}
		}

		if (count(array_unique($texts)) !== count($texts)) {
			self::refuse($raw, $path, ['each text once'], false, 'a text given twice');
		}

		return new Value($this, $texts, $raw);
	}
}
