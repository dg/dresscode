<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{Domain, Value};


/**
 * A value read by a grammar of its own, an entry of a map of the upgrading data (`setSubject($value)`,
 * `{write: …, risk: behaviorChanges}`, an entity of NEON, null for a ban without a sentence): the rule owning the
 * decision reads it and refuses what its grammar does not take when the rule is built.
 */
final readonly class GrammarEntry extends Domain
{
	public function toArray(): array
	{
		return ['kind' => 'grammarEntry'];
	}


	public function describe(): string
	{
		return 'an entry of the grammar of the map';
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		return new Value($this, $raw, $raw);
	}
}
