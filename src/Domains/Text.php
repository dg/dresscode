<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function is_string;


/**
 * A text of the project's own, which the rule writes as it is given (`break omitted`), where no list of words or
 * shapes could hold what projects write.
 */
final readonly class Text extends Domain
{
	public function toArray(): array
	{
		return ['kind' => 'text'];
	}


	public function describe(): string
	{
		return 'a text';
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		return is_string($raw) && trim($raw) !== ''
			? new Value($this, $raw, $raw)
			: self::refuse($raw, $path, ['a text'], $keep);
	}
}
