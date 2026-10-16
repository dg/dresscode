<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Domain, Gap, Line, Space};
use DressCode\Domains\Words;


/**
 * The claims of the space just inside a pair of delimiters that a place of `spacing.padding` decides: none, or with
 * `required` a single space on each side of what stands between them, an empty pair staying empty.
 * @internal
 */
final class Padding
{
	public static function createDomain(): Words
	{
		return Domain::state('forbidden', 'required');
	}


	/**
	 * The claim after the opening delimiter and before the closing one, null where the place is kept.
	 * @return array{Claim|\Closure(Gap): Claim|null, Claim|\Closure(Gap): Claim|null}
	 */
	public static function createClaims(string $decision, ?string $word, ?Line $line = null): array
	{
		if ($word === null) {
			return [null, null];
		}

		$none = new Claim(Space::None, line: $line, decision: $decision);
		if ($word === 'forbidden') {
			return [$none, $none];
		}

		$single = new Claim(Space::Single, line: $line, decision: $decision);
		return [
			fn(Gap $gap) => $gap->token->getNext()?->is([')', ']']) ? $none : $single,
			fn(Gap $gap) => $gap->token->getPrevious()?->is(['(', '[']) ? $none : $single,
		];
	}
}
