<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use function sprintf;


/**
 * A value `dresscode init` writes for a decision, departing from the standard, with what it rests on.
 * @internal
 */
final readonly class ProposedValue
{
	public function __construct(
		public string $path,
		/** as the configuration writes it, `keep` for a decision left unchecked */
		public mixed $value,
		/** the evidence, written beside the value: "your code, 93% of 176 files", "not checked: …" */
		public string $comment,
		public ValueOrigin $origin = ValueOrigin::Measured,
		/** for a decision the budget left unchecked: the value as written that the code or the standard holds to */
		public ?string $wanted = null,
		/** for a decision the budget left unchecked: the files its fix would change */
		public int $cost = 0,
	) {
	}


	/** A decision whose fix would change more files than the budget allows, left unchecked. */
	public static function overBudget(string $path, string $wanted, int $cost, int $scopeFiles): self
	{
		return new self(
			$path,
			'keep',
			sprintf('not checked: a fix would change %d of %d files', $cost, $scopeFiles),
			ValueOrigin::OverBudget,
			$wanted,
			$cost,
		);
	}


	public function isKept(): bool
	{
		return $this->value === 'keep';
	}
}
