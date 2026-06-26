<?php declare(strict_types=1);

namespace PhpSyntax;


/**
 * Node of the concrete syntax tree; every token of the source is reachable through the children.
 */
abstract class Node implements \Stringable
{
	public ?Node $parent = null;


	/**
	 * Children in source order, without empty slots.
	 * @return list<Node|Token>
	 */
	abstract public function getChildren(): array;


	public function __toString(): string
	{
		return Printer::print($this);
	}
}
