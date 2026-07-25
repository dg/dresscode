<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\AssignRefNode;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\IncludeNode;
use PhpSyntax\Nodes\Expression\PrintNode;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\Expression\ThrowNode;
use PhpSyntax\Nodes\Expression\YieldNode;
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
final class SymbolicLogicalOperatorsRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [BinaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryNode) {
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
		$node->setOperator($operator);
	}


	/**
	 * Whether the operand is held together only by the low precedence of `and`/`or`: with `&&`/`||`
	 * it would parse differently.
	 */
	private function bindsInBetween(ExpressionNode $operand): bool
	{
		return $operand instanceof AssignNode
			|| $operand instanceof AssignRefNode
			|| $operand instanceof TernaryNode
			|| $operand instanceof YieldNode
			|| $operand instanceof PrintNode
			|| $operand instanceof IncludeNode
			|| $operand instanceof ThrowNode
			|| $operand instanceof ArrowFunctionNode
			|| ($operand instanceof BinaryNode
				&& $operand->operator->is(TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor, TokenKind::Coalesce));
	}
}
