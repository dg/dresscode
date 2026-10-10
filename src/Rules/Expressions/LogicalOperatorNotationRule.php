<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode, OperatorNode};


/**
 * The symbolic `&&` and `||` instead of the wordy `and` and `or`; only where no operand binds between
 * the two precedence levels, so the meaning cannot change. `xor` has no symbolic equivalent and stays.
 */
#[RuleInfo(Stage::Structure)]
final class LogicalOperatorNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.wordLogicalOperators', Domain::state('forbidden'), '`and`, `or` bind weaker than `=` and are written `&&`, `||`')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\BinaryOpNode) {
			return;
		}

		$text = match (true) {
			$node->operator->is(Token::LogicalAnd) => '&&',
			$node->operator->is(Token::LogicalOr) => '||',
			default => null,
		};
		if ($text === null) {
			return;
		}

		static $precedences = [];
		$precedence = $precedences[$text] ??= (new Builder)->binary(0, $text, 0)->precedence;
		if (
			self::bindsLooser($node->left, $precedence, right: false)
			|| self::bindsLooser($node->right, $precedence, right: true)
			|| !$context->report($node->operator, "The `{$node->operator->text}` operator must be written `$text`.")
		) {
			return;
		}

		$node->operator->replaceWith(Token::fromText($text));
	}


	/**
	 * Whether the operand is held together only by the low precedence of `and`/`or`: under the symbolic operator
	 * it would parse differently. Both lean to the left, so an operand of the same precedence may stand on the left
	 * and not on the right.
	 */
	private static function bindsLooser(ExpressionNode $operand, int $precedence, bool $right): bool
	{
		if (!$operand instanceof OperatorNode) {
			return false;
		}

		$own = $operand->precedence;
		return $right ? $own <= $precedence : $own < $precedence;
	}
}
