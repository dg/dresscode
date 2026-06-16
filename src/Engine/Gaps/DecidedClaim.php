<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine\Gaps;

use DressCode\{Claim, Rule};
use PhpSyntax\{Node, Token};


/**
 * The claim that won one component of a gap.
 * @template-covariant T of \DressCode\Line|\DressCode\Space|int|array{int, ?int}
 * @internal
 */
final readonly class DecidedClaim
{
	public function __construct(
		public Rule $rule,
		/** @var T  what the claim asks of the component */
		public mixed $wanted,
		/** what the claim was made for */
		public Node|Token $subject,
		public Claim $claim,
		/** the construct the closure of the claim decided about */
		public ?Node $construct,
		/** @var 'after'|'before'  the side of the gap the claim stands on, after the first token or before the second */
		public string $side,
	) {
	}
}
