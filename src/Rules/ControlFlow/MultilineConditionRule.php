<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\{ElseifNode, ExpressionNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, ParenthesizedNode, UnaryOpNode};
use PhpSyntax\Nodes\Statement\{DoWhileNode, IfNode, WhileNode};
use function count;


/**
 * A condition of `if`, `elseif`, `while` and `do-while` joined by boolean operators is written in one of two shapes:
 * `perLine` begins it on the line after the opening parenthesis, `compact` on the line of it, and both leave
 * the closing parenthesis on a line of its own, so that the brace of the body stands apart from the condition.
 * How the parts are spread over the lines between is the author's; a condition in no shape the configuration
 * allows is written again in the first of them, with a line per part beginning with its boolean operator, and
 * so is one that stands on a single line wider than the line length of the style. The width is measured as
 * `LineLengthRule` measures a line, a tab counting to the next stop of the style, and up to the closing
 * parenthesis, because what follows belongs to other rules and may still move. A boolean operator ending a line
 * of a condition left as it is moves to the start of the next one, as `multiline.operatorPosition.condition`
 * says. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, analyses: [IndentationPlan::class])]
final class MultilineConditionRule extends GapRule
{
	private const PerLine = 'perLine';
	private const Compact = 'compact';
	private const Shape = 'multiline.condition';
	private const Position = 'multiline.operatorPosition.condition';

	/** @var list<string>  the shapes that pass, the first of them the one a condition in none of them is written in */
	private array $shapes = [self::PerLine];

	private bool $operatorsAtStart = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Shape, new Words([
				self::PerLine => 'begins on the line after the opening parenthesis',
				self::Compact => 'begins on the line of the opening parenthesis',
			], tolerance: true), 'The shape of a condition of `if`, `elseif`, `while` and `do-while` joined by boolean operators that stands on several lines or on one too wide, the closing parenthesis on a line of its own; one in no shape that passes is written again in the first, a line per part beginning with its operator'),
			new Decision(self::Position, Domain::lineStart(), 'Where a boolean operator chaining a condition of `if`, `elseif`, `while` and `do-while` stands at a line break'),
		];
	}


	public function configure(Values $values): void
	{
		$this->shapes = $values->isKept(self::Shape) ? [] : $values->get(self::Shape)->getWords();
		$this->operatorsAtStart = !$values->isKept(self::Position);
	}


	public function getClaims(): array
	{
		$statement = [
			'condition' => [fn(Gap $gap) => $this->claimToBegin($gap, $gap->value->parent), null],
			'closeParen' => [fn(Gap $gap) => $this->claimToEnd($gap, $gap->token->parent), null],
		];
		return [
			IfNode::class => $statement,
			ElseifNode::class => $statement,
			WhileNode::class => $statement,
			DoWhileNode::class => $statement,
			BinaryOpNode::class => [
				// the part begins with its operator, so what follows the operator stays on its line
				'operator' => [
					fn(Gap $gap) => $this->claimForPart($gap, Line::Next),
					fn(Gap $gap) => $this->claimForPart($gap, Line::Same),
				],
			],
		];
	}


	/** Where the condition begins is the whole difference between the two shapes. */
	private function claimToBegin(Gap $gap, ?Node $node): ?Claim
	{
		$decision = $this->decideShape($gap, $node);
		return $decision === null
			? null
			: new Claim(line: $decision[0] === self::PerLine ? Line::Next : Line::Same, because: $decision[1], decision: self::Shape);
	}


	/** Both shapes leave the closing parenthesis on a line of its own. */
	private function claimToEnd(Gap $gap, ?Node $node): ?Claim
	{
		$decision = $this->decideShape($gap, $node);
		return $decision === null ? null : new Claim(line: Line::Next, because: $decision[1], decision: self::Shape);
	}


	/**
	 * The break around a boolean operator of the chain of the condition: the one the shape asks for when the
	 * condition is written again, otherwise, with the operators at the start, the break after an operator ending
	 * a line moved in front of it.
	 */
	private function claimForPart(Gap $gap, Line $line): ?Claim
	{
		$part = $gap->token->parent;
		$statement = $part instanceof BinaryOpNode ? NodeHelpers::findConditionStatement($part) : null;
		if ($statement === null) {
			return null;
		}

		$decision = $this->decideShape($gap, $statement);
		return match (true) {
			$decision !== null => new Claim(line: $line, because: $decision[1], decision: self::Shape),
			$this->operatorsAtStart && in_array($gap->token, $this->findOperatorsEndingLine($gap, $statement), true)
				=> new Claim(line: $line, decision: self::Position),
			default => null,
		};
	}


	/**
	 * The boolean operators of the chain of the condition that end their line, decided once per pass about the
	 * statement.
	 * @return list<Token>
	 */
	private function findOperatorsEndingLine(Gap $gap, IfNode|ElseifNode|WhileNode|DoWhileNode $statement): array
	{
		return $gap->once($statement, fn() => array_values(array_filter(
			self::collectChainOperators($statement->condition),
			fn(Token $token) => $token->isFollowedByLineEnding(),
		)));
	}


	/**
	 * The shape the condition of the statement must be written in and why, or null when it may stay as it is.
	 * Decided once per pass about the condition, from the shape it has when the first of its gaps is reached.
	 * @return ?array{string, string}
	 */
	private function decideShape(Gap $gap, ?Node $node): ?array
	{
		if (
			!$node instanceof IfNode
			&& !$node instanceof ElseifNode
			&& !$node instanceof WhileNode
			&& !$node instanceof DoWhileNode
		) {
			return null;
		}

		return $gap->once($node->condition, function () use ($node, $gap): ?array {
			$because = $this->findReasonToRewrite($node, $gap);
			return $because === null ? null : [$this->shapes[0], $because];
		});
	}


	/**
	 * Why the condition must be written again: it stands on several lines in no shape the configuration allows,
	 * or on a single line wider than the line length of the style, measured once the line is indented.
	 */
	private function findReasonToRewrite(IfNode|ElseifNode|WhileNode|DoWhileNode $node, Gap $gap): ?string
	{
		if ($this->shapes === [] || self::countOperators($node->condition) === 0) {
			return null;
		}

		// several lines is a property of the parentheses, not of the chain: a condition on one line between
		// parentheses of their own is written in no shape either
		if ($node->openParen->getCurrentLine() !== $node->closeParen->getCurrentLine()) {
			return in_array(self::findShape($node), $this->shapes, true)
				? null
				: (count($this->shapes) === 1
					? "the condition is not in the `{$this->shapes[0]}` shape"
					: 'the condition fits none of the allowed shapes');
		}

		$close = $node->closeParen;
		$style = $gap->style;
		if ($style->maxLineLength === null) {
			return null;
		}

		// the column first: asking whether the line is in place costs a plan of the whole file after every edit
		$phpSyntax = $style->toPhpSyntax();
		$column = Indentation::advance(($close->getVisualColumn($phpSyntax) ?? 1) - 1, $close->text, $phpSyntax);
		return $column > $style->maxLineLength && NodeHelpers::isLineInPlace($gap, $close) ? "the condition reaches column $column, more than $style->maxLineLength" : null;
	}


	/** The shape a condition standing on several lines is written in, null for neither. */
	private static function findShape(IfNode|ElseifNode|WhileNode|DoWhileNode $node): ?string
	{
		$first = $node->condition->getFirstToken();
		if (!$node->closeParen->startsLine()) {
			return null;
		}

		return $first->startsLine() ? self::PerLine : self::Compact;
	}


	/**
	 * The boolean operators of the chain, in the order they are written; parentheses and negations end it.
	 * @return list<Token>
	 */
	private static function collectChainOperators(ExpressionNode $expr): array
	{
		return $expr instanceof BinaryOpNode && $expr->isLogical()
			? [...self::collectChainOperators($expr->left), $expr->operator, ...self::collectChainOperators($expr->right)]
			: [];
	}


	/**
	 * Boolean operators the condition is built of, seen through the parentheses and the negations around them;
	 * one inside an argument, a `match` arm or a closure is not the condition's and does not count.
	 */
	private static function countOperators(ExpressionNode $expr): int
	{
		return match (true) {
			$expr instanceof BinaryOpNode && $expr->isLogical() => 1 + self::countOperators($expr->left) + self::countOperators($expr->right),
			$expr instanceof ParenthesizedNode, $expr instanceof UnaryOpNode => self::countOperators($expr->expression),
			default => 0,
		};
	}
}
