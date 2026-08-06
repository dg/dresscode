<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * `isset($a) ? $a : $b` and `$a !== null ? $a : $b` are `$a ?? $b`. The repeated expression must be one
 * that can be read again without side effects.
 */
#[RuleInfo(
	'dresscode/null-coalescing-operator',
	Stage::Structure,
	description: 'Replaces a ternary testing for null with the null coalescing operator',
)]
final class NullCoalescingOperatorRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TernaryNode || $node->if === null) {
			return;
		}

		$cond = $node->cond;
		if ($cond instanceof IssetNode && count($cond->vars) === 1) {
			[$subject, $value, $default] = [$cond->vars->getItems()[0], $node->if, $node->else];
		} elseif (
			$cond instanceof BinaryNode
			&& $cond->operator->is(TokenKind::IsIdentical, TokenKind::IsNotIdentical)
		) {
			$subject = match (true) {
				self::isNull($cond->right) => $cond->left,
				self::isNull($cond->left) => $cond->right,
				default => null,
			};
			[$value, $default] = $cond->operator->is(TokenKind::IsNotIdentical) ? [$node->if, $node->else] : [$node->else, $node->if];
		} else {
			return;
		}

		if (
			$subject === null
			|| !$subject->isRepeatableRead()
			|| !$subject->matches($value)
			|| $node->hasComment()
			|| !$context->report($node->question, "A ternary testing for null must be written with '??'")
		) {
			return;
		}

		$coalesce = (new Parser)->parseExpression('0 ?? 0');
		assert($coalesce instanceof BinaryNode);
		$coalesce->left->replaceWith(self::copy($subject));
		if ($default instanceof TernaryNode || $default instanceof AssignNode) {
			$parenthesized = (new Parser)->parseExpression('(0)');
			assert($parenthesized instanceof ParenthesizedNode);
			$parenthesized->expr->replaceWith(self::copy($default));
			$coalesce->right->replaceWith($parenthesized);
		} else {
			$coalesce->right->replaceWith(self::copy($default));
		}

		$node->replaceWith($coalesce);
	}


	private static function isNull(ExpressionNode $expr): bool
	{
		return $expr instanceof ConstantFetchNode && strtolower($expr->name->getName()) === 'null';
	}


	private static function copy(ExpressionNode $expr): ExpressionNode
	{
		$copy = clone $expr;
		$copy->getFirstToken()?->setLeadingTrivia([]);
		$copy->getLastToken()?->setTrailingTrivia([]);
		return $copy;
	}
}
