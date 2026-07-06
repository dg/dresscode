<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\Node;
use PhpSyntax\Token;


/**
 * A rule that visits the nodes and tokens of the types it names, in the traversal of its stage, and works
 * on them in enter() and leave(); beforeFile() and afterFile() frame the traversal.
 */
abstract class NodeRule extends Rule
{
	/**
	 * Classes or interfaces of nodes (or Token::class) the rule wants to visit; instances of subclasses count too.
	 * An empty list means the rule works only in beforeFile() and afterFile().
	 * @return list<class-string>
	 */
	abstract public function getVisitedTypes(): array;


	public function enter(Node|Token $node, RuleContext $context): void
	{
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
	}


	public function beforeFile(RuleContext $context): void
	{
	}


	public function afterFile(RuleContext $context): void
	{
	}
}
