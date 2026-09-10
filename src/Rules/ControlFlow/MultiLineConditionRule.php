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
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\UnaryOpNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Style;
use PhpSyntax\TokenKind;
use function count, in_array, is_array;


/**
 * A condition of if, elseif, while and do-while joined by boolean operators is written in one of two shapes:
 * `perLine` begins it on the line after the opening parenthesis, `compact` on the line of it, and both leave
 * the closing parenthesis on a line of its own, so that the brace of the body stands apart from the condition.
 * How the parts are spread over the lines between is the author's; a condition in neither shape is written
 * again with a line per part, and so is one that stands on a single line reaching `minLineLength`. The option
 * names the shapes that pass, the first of them the one anything else is written in. The width is measured
 * as dresscode/line-length measures a line, a tab counting to the next stop of the style, and up to the
 * closing parenthesis when that shares the line, because what follows belongs to other rules and may still
 * move. Where the lines stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-condition',
	Stage::Formatting,
	description: 'Writes a condition of if, elseif, while and do-while in the shape the configuration asks for',
)]
final class MultiLineConditionRule extends GapRule implements ConfigurableRule
{
	private const PerLine = 'perLine';
	private const Compact = 'compact';
	private const Keep = 'keep';

	private const BooleanOperators = [
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];

	private int $minLineLength = 121;

	/** @var list<string>  the shapes that pass, the first of them the one a condition in none of them is written in */
	private array $shapes = [self::PerLine];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minLineLength' => Expect::int(121)->min(1)->description('A condition whose line reaches this width, its closing parenthesis included, is broken; what follows on the line does not count, and dresscode/line-length reports a line of one less'),
			'shape' => Expect::anyOf(self::PerLine, self::Compact, self::Keep, Expect::listOf(Expect::anyOf(self::PerLine, self::Compact))->min(1))
				->default(self::PerLine)
				->description('The shape of a condition on several lines: perLine begins it on the line after the parenthesis, compact on the line of it, both closing the parenthesis on a line of its own; a list of the two lets either pass and writes anything else in the first, keep leaves every condition as it is'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minLineLength = $options['minLineLength'];
		$this->shapes = match (true) {
			is_array($options['shape']) => array_values(array_unique($options['shape'])),
			$options['shape'] === self::Keep => [],
			default => [$options['shape']],
		};
	}


	public function getClaims(): array
	{
		$statement = [
			'condition' => [fn(Gap $gap) => $this->claimToBegin($gap, $gap->value->parent), null],
			'closeParen' => [fn(Gap $gap) => $this->claimToEnd($gap, $gap->token->parent), null],
		];
		return [
			IfNode::class => $statement,
			ElseIfNode::class => $statement,
			WhileNode::class => $statement,
			DoWhileNode::class => $statement,
			BinaryOpNode::class => [
				// the part begins with its operator, so what follows the operator stays on its line
				'operator' => [
					fn(Gap $gap) => $this->claimForPart($gap, $gap->token->parent, Line::Next),
					fn(Gap $gap) => $this->claimForPart($gap, $gap->token->parent, Line::Same),
				],
			],
		];
	}


	/** Where the condition begins is the whole difference between the two shapes. */
	private function claimToBegin(Gap $gap, ?Node $node): ?Claim
	{
		$decision = $this->decide($gap, $node);
		return $decision === null
			? null
			: new Claim(line: $decision[0] === self::PerLine ? Line::Next : Line::Same, because: $decision[1]);
	}


	/** Both shapes leave the closing parenthesis on a line of its own. */
	private function claimToEnd(Gap $gap, ?Node $node): ?Claim
	{
		$decision = $this->decide($gap, $node);
		return $decision === null ? null : new Claim(line: Line::Next, because: $decision[1]);
	}


	/**
	 * The break of the condition the part belongs to, when the part lies on the way from that condition down
	 * through the boolean operators of its chain, which is where the shape reaches.
	 */
	private function claimForPart(Gap $gap, ?Node $part, Line $line): ?Claim
	{
		if (!self::isChained($part)) {
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
				$decision = $parent->condition === $node ? $this->decide($gap, $parent) : null;
				return $decision === null ? null : new Claim(line: $line, because: $decision[1]);
			}

			if (!self::isChained($parent)) {
				return null;
			}
		}

		return null;
	}


	/**
	 * The shape the condition of the statement must be written in and why, or null when it may stay as it is.
	 * Decided once per pass about the condition, from the shape it has when the first of its gaps is reached.
	 * @return ?array{string, string}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if (
			!$node instanceof IfNode
			&& !$node instanceof ElseIfNode
			&& !$node instanceof WhileNode
			&& !$node instanceof DoWhileNode
		) {
			return null;
		}

		return $gap->once($node->condition, function () use ($node, $gap): ?array {
			$because = $this->reasonToWrite($node, $gap->style);
			return $because === null ? null : [$this->shapes[0], $because];
		});
	}


	/**
	 * Why the condition must be written again: it stands on several lines in no shape the configuration allows,
	 * or on a single line of width minLineLength or more, so the widest one that passes is one character narrower.
	 */
	private function reasonToWrite(IfNode|ElseIfNode|WhileNode|DoWhileNode $node, Style $style): ?string
	{
		$cond = $node->condition;
		$first = $cond->getFirstToken();
		$last = $cond->getLastToken();
		if ($this->shapes === [] || $first === null || $last === null || self::countOperators($cond) === 0) {
			return null;
		}

		// several lines is a property of the parentheses, not of the chain: a condition on one line between
		// parentheses of their own is written in no shape either
		if ($node->openParen->getLine() !== $node->closeParen->getLine()) {
			return in_array(self::shapeOf($node), $this->shapes, strict: true)
				? null
				: (count($this->shapes) === 1
					? "the condition is not written in the '{$this->shapes[0]}' shape"
					: 'the condition is written in none of the allowed shapes');
		}

		// the width of the condition itself, with its closing parenthesis when that shares the line: what follows
		// on the line is the business of other rules and may still move
		$end = $node->closeParen->getLine() === $last->getLine() ? $node->closeParen : $last;
		$column = Indentation::advance(($end->getVisualColumn($style) ?? 1) - 1, $end->text, $style);
		return $column >= $this->minLineLength ? "the condition reaches column $column" : null;
	}


	/**
	 * The shape a condition standing on several lines is written in: where it begins is the whole difference,
	 * and both shapes leave the closing parenthesis on a line of its own, so that the brace of the body stands
	 * apart from the condition. Null for a condition that is in neither, whose parts are then given a line each.
	 */
	private static function shapeOf(IfNode|ElseIfNode|WhileNode|DoWhileNode $node): ?string
	{
		$first = $node->condition->getFirstToken();
		if ($first === null || !$node->closeParen->startsLine()) {
			return null;
		}

		return $first->startsLine() ? self::PerLine : self::Compact;
	}


	/** The chain reaches through boolean operators only, so a part on its own line is a part of the condition. */
	private static function isChained(?Node $expr): bool
	{
		return $expr instanceof BinaryOpNode && $expr->operator->is(...self::BooleanOperators);
	}


	/**
	 * Boolean operators the condition is built of, seen through the parentheses and the negations around them;
	 * one inside an argument, a match arm or a closure is not the condition's and does not count.
	 */
	private static function countOperators(ExpressionNode $expr): int
	{
		return match (true) {
			$expr instanceof BinaryOpNode => self::isChained($expr)
				? 1 + self::countOperators($expr->left) + self::countOperators($expr->right)
				: 0,
			$expr instanceof ParenthesizedNode, $expr instanceof UnaryOpNode => self::countOperators($expr->expression),
			default => 0,
		};
	}
}
