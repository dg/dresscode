<?php declare(strict_types=1);

namespace DressCode;


/**
 * A rule about the gaps between tokens declares the sides of the slots it governs and what it claims there:
 * the whitespace of a line, the line the second token stands on, blank lines. The engine applies every such
 * rule in one walk over the tokens, resolves the two sides of a gap and reports under the name of the rule
 * whose claim it is. Two rules claiming the same component of the same side of the same slot are a
 * configuration error, so that a gap has one owner. The rule visits nothing itself.
 */
abstract class GapRule extends Rule
{
	/**
	 * Node class, or '*' for every node with the slot → slot (`'body'`), an item of a list
	 * (`'statements:item'`) or its separators (`'items:separator'`) → [before, after]: the claim,
	 * null for none, or a closure deciding by the gap and returning null to abstain. A claim on a slot holding
	 * a node applies to its first (before) or last (after) token; a claim of a class comes before one of '*'.
	 * @return array<string, array<string, array{Claim|\Closure(Gap): ?Claim|null, Claim|\Closure(Gap): ?Claim|null}>>
	 */
	abstract public function getClaims(): array;
}
