<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use function is_array;


/**
 * How a run treats what the rules report, the same for every file of one configuration: what silences a report, what
 * only warns, which fixes that may change what the code does it makes, and whether a broken rule contract throws.
 * @internal
 */
final readonly class ReportPolicy
{
	/** @var \Closure(string): list<string>  a name in a suppression comment => the decisions it stands for */
	public \Closure $expandName;


	/** @param ?\Closure(string): list<string> $expandName  without one a name stands for itself */
	public function __construct(
		?\Closure $expandName = null,
		/** @var array<string, list<string>>  pattern of a comment => the decisions it silences where it stands */
		public array $suppressionComments = [],
		/** @var array<string, true>  decisions whose violations only warn */
		public array $warnOnly = [],
		/** @var bool|array<string, true>  whether the run may make a fix that changes what the code does: every one, or those of the decisions */
		public bool|array $fixRisky = false,
		/** a broken rule contract throws instead of warning */
		public bool $strict = false,
	) {
		$this->expandName = $expandName ?? fn(string $name): array => [$name];
	}


	/** Whether the run makes the fixes of the decision that may change what the code does. */
	public function acceptsRisk(string $decision): bool
	{
		return $this->fixRisky === true || (is_array($this->fixRisky) && isset($this->fixRisky[$decision]));
	}
}
