<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\Node;
use PhpSyntax\Token;


/**
 * A rule inspects and fixes one aspect of style. It is stateless across files: an instance serves the whole
 * run, per-file state goes to RuleContext::getStorage(). Every mutation of the tree must follow a report()
 * that returned true.
 */
abstract class Rule
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
