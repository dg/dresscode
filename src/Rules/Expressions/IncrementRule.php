<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{PostfixOpNode, PrefixOpNode};
use PhpSyntax\Nodes\Statement\{ExpressionStatementNode, ForNode};


/**
 * An increment or a decrement whose value nobody reads, a statement or a step of `for`, is written in the form of
 * `expressions.assignment.increment`, `++$i` or `$i++`; one inside an expression gives its value and stays as it is.
 */
#[RuleInfo(Stage::Structure, decisions: ['expressions.assignment.increment'])]
final class IncrementRule extends NodeRule
{
	private bool $prefix = false;


	public function configure(Values $values): void
	{
		$this->prefix = $values->get('expressions.assignment.increment')->getWord() === 'prefix';
	}


	public function getVisitedNodes(): array
	{
		return [$this->prefix ? PostfixOpNode::class : PrefixOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof PostfixOpNode && !$node instanceof PrefixOpNode)
			|| !$node->operator->is(['++', '--'])
			|| !self::isUnread($node)
			|| $node->hasInnerComment()
		) {
			return;
		}

		$operator = $node->operator->text;
		$written = $this->prefix ? $operator . $node->target->text : $node->target->text . $operator;
		if ($context->report($node, "The `$node->text` whose value is not read must be written `$written`.")) {
			$node->replaceWith((new Builder)->expression($this->prefix ? "$operator\$target" : "\$target$operator", target: $node->target));
		}
	}


	private static function isUnread(PostfixOpNode|PrefixOpNode $node): bool
	{
		$parent = $node->parent;
		return $parent instanceof ExpressionStatementNode
			|| ($parent?->parent instanceof ForNode && $parent === $parent->parent->updates);
	}
}
