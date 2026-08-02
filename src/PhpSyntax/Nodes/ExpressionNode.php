<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayDimFetchNode;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;


abstract class ExpressionNode extends Node
{
	/**
	 * Whether the parent reads a member, an element or a static member of this expression, or calls it:
	 * `$this->x`, `$this[0]`, `$this::y()`, `$this()`. Such an expression needs parentheses unless the
	 * grammar makes it a primary one.
	 */
	public function isDereferenced(): bool
	{
		$parent = $this->parent;
		return match (true) {
			$parent instanceof MethodCallNode, $parent instanceof PropertyFetchNode => $parent->object === $this,
			$parent instanceof StaticCallNode, $parent instanceof StaticPropertyFetchNode, $parent instanceof ClassConstantFetchNode => $parent->class === $this,
			$parent instanceof ArrayDimFetchNode => $parent->var === $this,
			$parent instanceof FunctionCallNode => $parent->name === $this,
			default => false,
		};
	}
}
