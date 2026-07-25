<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
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
		if (
			$kind === null
			|| $text === null
			|| $this->bindsInBetween($node->left)
			|| $this->bindsInBetween($node->right)
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
	 * Whether the operand is held together only by the low precedence of `and`/`or`: with `&&`/`||`
	 * it would parse differently.
	 */
	private function bindsInBetween(ExpressionNode $operand): bool
	{
		return $operand instanceof Expression\AssignmentNode
			|| $operand instanceof Expression\CombinedAssignmentNode
			|| $operand instanceof Expression\AssignmentByReferenceNode
			|| $operand instanceof Expression\TernaryNode
			|| $operand instanceof Expression\YieldNode
			|| $operand instanceof Expression\PrintNode
			|| $operand instanceof Expression\IncludeNode
			|| $operand instanceof Expression\ThrowNode
			|| $operand instanceof Expression\ArrowFunctionNode
			|| ($operand instanceof Expression\BinaryOpNode
				&& $operand->operator->is(TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor, TokenKind::Coalesce));
	}
}
