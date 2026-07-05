<?php declare(strict_types=1);

namespace PhpSyntax;


/**
 * Pre-order walk over nodes and tokens with enter and leave callbacks. A callback may replace or remove
 * the node it received: the walk then does not descend into it or its replacement and skips its leave;
 * siblings detached meanwhile are skipped too, and nodes inserted meanwhile are left for the next walk.
 */
final class Traverser
{
	/**
	 * @param ?\Closure(Node|Token): void $enter
	 * @param ?\Closure(Node|Token): void $leave
	 */
	public function __construct(
		private readonly ?\Closure $enter = null,
		private readonly ?\Closure $leave = null,
	) {
	}


	public function traverse(Node $root): void
	{
		$this->visit($root, $root->parent);
	}


	private function visit(Node|Token $node, ?Node $parent): void
	{
		$this->enter?->__invoke($node);
		if ($node->parent !== $parent) {
			return;
		}

		if ($node instanceof Node) {
			foreach ($node->getChildren() as $child) { // a snapshot: the callbacks may change the children
				if ($child->parent === $node) {
					$this->visit($child, $node);
				}
			}

			if ($node->parent !== $parent) {
				return;
			}
		}

		$this->leave?->__invoke($node);
	}
}
