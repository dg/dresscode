<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\OperatorNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * The symbolic `&&` and `||` instead of the wordy `and` and `or`; only where no operand binds between
 * the two precedence levels, so the meaning cannot change. `xor` has no symbolic equivalent and stays.
 */
#[RuleInfo(
	'dresscode/symbolic-logical-operators',
	Stage::Structure,
	description: 'Uses && and || instead of and and or',
)]
final class SymbolicLogicalOperatorsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\BinaryOpNode) {
			return;
		}

		[$kind, $text] = match (true) {
			$node->operator->is(TokenKind::LogicalAnd) => [TokenKind::BooleanAnd, '&&'],
			$node->operator->is(TokenKind::LogicalOr) => [TokenKind::BooleanOr, '||'],
			default => [null, null],
		};
		if ($kind === null || $text === null) {
			return;
		}

		$symbolic = (new Parser)->parseExpression("0 $text 0");
		assert($symbolic instanceof Expression\BinaryOpNode);
		[$precedence] = $symbolic->getPrecedence();
		if (
			self::bindsLooser($node->left, $precedence, right: false)
			|| self::bindsLooser($node->right, $precedence, right: true)
			|| !$context->report($node->operator, "The {$node->operator->text} operator must be written '$text'")
		) {
			return;
		}

		$operator = new Token($kind, $text);
		$operator->setLeadingTrivia($node->operator->leadingTrivia);
		$operator->setTrailingTrivia($node->operator->trailingTrivia);
		$node->operator = $operator;
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

		[$own] = $operand->getPrecedence();
		return $right ? $own <= $precedence : $own < $precedence;
	}
}
