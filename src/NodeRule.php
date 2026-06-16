<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\{Node, Token};


/**
 * A rule that visits the nodes and tokens of the classes it names, in the traversal of its stage, and works
 * on them in `enter()` and `leave()`; `beforePass()` and `afterPass()` frame the traversal, which every pass over
 * the file makes again.
 */
abstract class NodeRule extends Rule
{
	/**
	 * Classes or interfaces of nodes (or `Token::class`) the rule wants to visit; instances of subclasses count too.
	 * An empty list means the rule works only in `beforePass()` and `afterPass()`.
	 * @return list<class-string>
	 */
	abstract public function getVisitedNodes(): array;


	public function enter(Node|Token $node, RuleContext $context): void
	{
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
	}


	public function beforePass(RuleContext $context): void
	{
	}


	public function afterPass(RuleContext $context): void
	{
	}
}
