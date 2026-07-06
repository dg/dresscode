<?php declare(strict_types=1);

namespace DressCode\Engine\Gaps;

use DressCode\Rule;
use PhpSyntax\Node;


/**
 * What the closures of the gap rules decided about the nodes during a pass, by rule and node, so that a claim
 * depending on the shape of the text answers every gap of a construct alike; the engine makes a new one when
 * a pass begins.
 * @internal
 */
final class Memory
{
	/** @var array<int, array<int, array{Node, mixed}>>  rule → node → the node, kept to tell a recycled id apart, and the decision */
	private array $decisions = [];


	/**
	 * @template T
	 * @param \Closure(): T $decide
	 * @return T
	 */
	public function once(Rule $rule, Node $node, \Closure $decide): mixed
	{
		$decided = $this->decisions[spl_object_id($rule)][spl_object_id($node)] ?? null;
		if ($decided !== null && $decided[0] === $node) {
			return $decided[1];
		}

		$decision = $decide();
		$this->decisions[spl_object_id($rule)][spl_object_id($node)] = [$node, $decision];
		return $decision;
	}
}
