<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Style;
use PhpSyntax\TokenKind;


/**
 * A long condition joined by boolean operators is split so that every part starts a line with its operator
 * and the closing parenthesis stands on a line of its own; a parenthesized group with operators inside is
 * split the same way. A condition whose chain already spreads over lines keeps its parts as they are but
 * starts on the line after the opening parenthesis and puts the closing one on a line of its own. Where the
 * lines stand is the matter of dresscode/indentation. A condition with a comment inside is left alone.
 */
#[RuleInfo(
	'dresscode/multi-line-condition',
	Stage::Formatting,
	description: 'Splits a long condition of if, elseif, while and do-while into one part per line',
)]
final class MultiLineConditionRule extends GapRule implements ConfigurableRule
{
	private const BooleanOperators = [
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];

	private int $minLineLength = 121;
	private bool $splitAllParts = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minLineLength' => Expect::int(121)->min(1)->description('A condition reaching this column or beyond, its closing parenthesis included, is split; what follows on the line does not count'),
			'splitAllParts' => Expect::bool(false)->description('A condition already on several lines is split further until every part has its own line'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minLineLength = $options['minLineLength'];
		$this->splitAllParts = $options['splitAllParts'];
	}


	public function getClaims(): array
	{
		$statement = [
			'condition' => [fn(Gap $gap) => $this->claimToSplit($gap, $gap->value->parent), null],
			'closeParen' => [fn(Gap $gap) => $this->claimToSplit($gap, $gap->token->parent), null],
		];
		return [
			IfNode::class => $statement,
			ElseIfNode::class => $statement,
			WhileNode::class => $statement,
			DoWhileNode::class => $statement,
			BinaryOpNode::class => [
				'operator' => [fn(Gap $gap) => $this->claimToLayOutPart($gap, $gap->token->parent), null],
			],
			ParenthesizedNode::class => [
				'expression' => [fn(Gap $gap) => $this->claimToLayOutPart($gap, $gap->value->parent), null],
				'closeParen' => [fn(Gap $gap) => $this->claimToLayOutPart($gap, $gap->token->parent), null],
			],
		];
	}


	/**
	 * The break, with its reason, of a statement whose condition begins on the line after the opening parenthesis
	 * and ends before the closing one on a line of its own: when it takes a line per part, or when its chain of
	 * boolean operators spreads over lines already and the parentheses do not follow. Decided once per pass
	 * about the statement, from the shape it has when the first of its gaps is reached.
	 */
	private function claimToSplit(Gap $gap, ?Node $node): ?Claim
	{
		if (
			!$node instanceof IfNode
			&& !$node instanceof ElseIfNode
			&& !$node instanceof WhileNode
			&& !$node instanceof DoWhileNode
		) {
			return null;
		}

		return $gap->once($node, fn() => $this->claimToLayOut($gap, $node)
			?? ($this->isHalfSplit($node) ? new Claim(line: Line::Next, because: 'the condition spans several lines') : null));
	}


	/**
	 * The break, with its reason, of a statement whose condition takes a line per part: when it stands on one
	 * line that is too long, or when the option asks for every part and some still share a line. A comment
	 * inside leaves it alone. Decided once per pass about the condition.
	 */
	private function claimToLayOut(Gap $gap, ?Node $node): ?Claim
	{
		if (
			!$node instanceof IfNode
			&& !$node instanceof ElseIfNode
			&& !$node instanceof WhileNode
			&& !$node instanceof DoWhileNode
		) {
			return null;
		}

		return $gap->once($node->condition, function () use ($node, $gap): ?Claim {
			$because = $this->reasonToLayOut($node, $gap->style);
			return $because === null ? null : new Claim(line: Line::Next, because: $because);
		});
	}


	/**
	 * A chain of boolean operators spread over lines whose parentheses do not follow: the condition shares the
	 * line of the opening one, or the closing one shares a line with the condition.
	 */
	private function isHalfSplit(IfNode|ElseIfNode|WhileNode|DoWhileNode $node): bool
	{
		$cond = $node->condition;
		$first = $cond->getFirstToken();
		return self::canLayOut($cond)
			&& self::hasSplitOperator($cond)
			&& !$node->openParen->hasCommentUpTo($node->closeParen)
			&& ($first?->getLine() === $node->openParen->getLine() || !$node->closeParen->startsLine());
	}


	/** Whether a boolean operator on the way down through the chain and its parenthesized groups begins a line. */
	private static function hasSplitOperator(ExpressionNode $expr): bool
	{
		if ($expr instanceof BinaryOpNode && $expr->operator->is(...self::BooleanOperators)) {
			return $expr->operator->startsLine() || self::hasSplitOperator($expr->left) || self::hasSplitOperator($expr->right);
		}

		return $expr instanceof ParenthesizedNode && self::hasSplitOperator($expr->expression);
	}


	/** The width of the line depends on the tab width of the style. */
	private function reasonToLayOut(IfNode|ElseIfNode|WhileNode|DoWhileNode $node, Style $style): ?string
	{
		$cond = $node->condition;
		$operators = self::countOperators($cond);
		$first = $cond->getFirstToken();
		$last = $cond->getLastToken();
		if (
			$operators === 0
			|| $first === null
			|| $last === null
			|| $node->openParen->hasCommentUpTo($node->closeParen)
		) {
			return null;
		}

		$lines = ($last->getLine() ?? 0) - ($first->getLine() ?? 0) + 1;
		if ($lines > 1) {
			return $this->splitAllParts && $lines < $operators + 1 ? 'some parts of the condition share a line' : null;
		}

		// the width of the condition itself, with its closing parenthesis when that shares the line: what follows
		// on the line is the business of other rules and may still move
		$end = $node->closeParen->getLine() === $last->getLine() ? $node->closeParen : $last;
		$column = ($end->getVisualColumn($style) ?? 0) + mb_strlen($end->text) - 1;
		return $column >= $this->minLineLength && ($first->getLine() === $node->openParen->getLine() || self::canLayOut($cond))
			? "the condition reaches column $column"
			: null;
	}


	/**
	 * The break of the condition the part belongs to, when the part lies on the way from a condition laid out part
	 * by part down through boolean operators and parenthesized groups with operators inside, which is where the
	 * layout reaches.
	 */
	private function claimToLayOutPart(Gap $gap, ?Node $part): ?Claim
	{
		if (!self::canLayOut($part)) {
			return null;
		}

		for ($node = $part; $node !== null; $node = $parent) {
			$parent = $node->parent;
			if (
				$parent instanceof IfNode
				|| $parent instanceof ElseIfNode
				|| $parent instanceof WhileNode
				|| $parent instanceof DoWhileNode
			) {
				return $parent->condition === $node ? $this->claimToLayOut($gap, $parent) : null;
			}

			if (!self::canLayOut($parent)) {
				return null;
			}
		}

		return null;
	}


	/** Whether laying the expression out changes anything: a chain of boolean operators, or a parenthesized one. */
	private static function canLayOut(?Node $expr): bool
	{
		return ($expr instanceof BinaryOpNode && $expr->operator->is(...self::BooleanOperators))
			|| ($expr instanceof ParenthesizedNode && self::countOperators($expr->expression) > 0);
	}


	private static function countOperators(ExpressionNode $expr): int
	{
		$count = 0;
		foreach ([$expr, ...$expr->find(BinaryOpNode::class)] as $node) {
			if ($node instanceof BinaryOpNode && $node->operator->is(...self::BooleanOperators)) {
				$count++;
			}
		}

		return $count;
	}
}
