<?php declare(strict_types=1);

namespace PhpSyntax\Parser;

use PhpSyntax\Node;
use PhpSyntax\Token;


/**
 * Node built for a grammar production without a semantic action; exists only until every production has one.
 */
final class GenericNode extends Node
{
	/** @param list<Node|Token> $children */
	public function __construct(
		public readonly int $rule,
		public readonly array $children,
	) {
	}


	public function getChildren(): array
	{
		return $this->children;
	}


	public function replaceChild(Node|Token $old, Node|Token $new): void
	{
		throw new \LogicException('A generic node cannot be modified.');
	}
}
