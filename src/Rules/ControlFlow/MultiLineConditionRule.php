<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\{ElseIfNode, ExpressionNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, ParenthesizedNode, UnaryOpNode};
use PhpSyntax\Nodes\Statement\{DoWhileNode, IfNode, WhileNode};
use function count, in_array, is_array;


/**
 * A condition of `if`, `elseif`, `while` and `do-while` joined by boolean operators is written in one of two shapes:
 * `perLine` begins it on the line after the opening parenthesis, `compact` on the line of it, and both leave
 * the closing parenthesis on a line of its own, so that the brace of the body stands apart from the condition.
 * How the parts are spread over the lines between is the author's; a condition in no shape the configuration
 * allows is written again in the first of them, with a line per part beginning with its boolean operator, and
 * so is one that stands on a single line wider than the line length of the style. The width is measured as
 * dresscode/line-length measures a line, a tab counting to the next stop of the style, and up to the closing
 * parenthesis, because what follows belongs to other rules and may still move. Where the lines stand is the
 * matter of dresscode/indentation.
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
	private const Start = 'start';

	/** @var list<string>  the shapes that pass, the first of them the one a condition in none of them is written in */
	private array $shapes = [self::PerLine];

	private string $operatorPosition = self::Keep;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'shape' => Expect::anyOf(self::PerLine, self::Compact, self::Keep, Expect::listOf(Expect::anyOf(self::PerLine, self::Compact))->min(1))
				->default(self::PerLine)
				->description('The shape of a condition on several lines: perLine begins it on the line after the parenthesis, compact on the line of it, both closing the parenthesis on a line of its own; a list of the two lets either pass and writes anything else in the first, keep leaves every condition as it is'),
			'operatorPosition' => Expect::anyOf(self::Keep, self::Start)->default(self::Keep)
				->description('Where a boolean operator of the condition at a line break stands: keep leaves it where it is, start moves one ending a line to the start of the next unless a comment follows it'),
		]);
	}


	public function configure(array $options): void
	{
		$this->shapes = match (true) {
			is_array($options['shape']) => array_values(array_unique($options['shape'])),
			$options['shape'] === self::Keep => [],
			default => [$options['shape']],
		};
		$this->operatorPosition = $options['operatorPosition'];
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
					fn(Gap $gap) => $this->claimForPart($gap, Line::Next),
					fn(Gap $gap) => $this->claimForPart($gap, Line::Same),
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

		$decision = $this->decide($gap, $statement);
		return match (true) {
			$decision !== null => new Claim(line: $line, because: $decision[1]),
			$this->operatorPosition === self::Start && in_array($gap->token, $this->findOperatorsEndingLine($gap, $statement), true)
				=> $line === Line::Next ? Claim::nextLine() : Claim::sameLine(),
			default => null,
		};
	}


	/**
	 * The boolean operators of the chain of the condition that end their line, decided once per pass about the
	 * statement.
	 * @return list<Token>
	 */
	private function findOperatorsEndingLine(Gap $gap, IfNode|ElseIfNode|WhileNode|DoWhileNode $statement): array
	{
		return $gap->once($statement, fn() => array_values(array_filter(
			self::collectChainOperators($statement->condition),
			NodeHelpers::isLineBrokenAfter(...),
		)));
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
			$because = $this->reasonToWrite($node, $gap);
			return $because === null ? null : [$this->shapes[0], $because];
		});
	}


	/**
	 * Why the condition must be written again: it stands on several lines in no shape the configuration allows,
	 * or on a single line wider than the line length of the style, measured once the line is indented.
	 */
	private function reasonToWrite(IfNode|ElseIfNode|WhileNode|DoWhileNode $node, Gap $gap): ?string
	{
		if ($this->shapes === [] || self::countOperators($node->condition) === 0) {
			return null;
		}

		// several lines is a property of the parentheses, not of the chain: a condition on one line between
		// parentheses of their own is written in no shape either
		if ($node->openParen->getLine() !== $node->closeParen->getLine()) {
			return in_array(self::shapeOf($node), $this->shapes, true)
				? null
				: (count($this->shapes) === 1
					? "the condition is not written in the '{$this->shapes[0]}' shape"
					: 'the condition is written in none of the allowed shapes');
		}

		$close = $node->closeParen;
		$style = $gap->style;
		if ($style->lineLength === null || !NodeHelpers::isLineInPlace($gap, $close)) {
			return null;
		}

		$phpSyntax = $style->toPhpSyntax();
		$column = Indentation::advance(($close->getVisualColumn($phpSyntax) ?? 1) - 1, $close->text, $phpSyntax);
		return $column > $style->lineLength ? "the condition reaches column $column" : null;
	}


	/** The shape a condition standing on several lines is written in, null for neither. */
	private static function shapeOf(IfNode|ElseIfNode|WhileNode|DoWhileNode $node): ?string
	{
		$first = $node->condition->getFirstToken();
		if ($first === null || !$node->closeParen->startsLine()) {
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
		return NodeHelpers::isLogicalOperation($expr)
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
			NodeHelpers::isLogicalOperation($expr) => 1 + self::countOperators($expr->left) + self::countOperators($expr->right),
			$expr instanceof ParenthesizedNode, $expr instanceof UnaryOpNode => self::countOperators($expr->expression),
			default => 0,
		};
	}
}
