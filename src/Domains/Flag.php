<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};
use function is_bool;


/**
 * `yes` or `no`, only where the decision is a boolean by its nature (`imports.order.caseSensitive`), never as
 * turning something on.
 */
final readonly class Flag extends Domain
{
	public function toArray(): array
	{
		return ['kind' => 'flag'];
	}


	public function describe(): string
	{
		return '`yes`; `no`';
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		return is_bool($raw)
			? new Value($this, $raw, $raw)
			: self::refuse($raw, $path, ['`yes`', '`no`'], $keep);
	}
}
