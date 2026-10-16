<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Domain, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DeclareItemNode, MatchArmNode};
use PhpSyntax\Nodes\Expression\{
	ArrowFunctionNode, AssignmentNode, BinaryOpNode, CombinedAssignmentNode, InstanceofNode, YieldNode,
};
use PhpSyntax\Nodes\Statement\ForeachNode;


/**
 * Spaces around binary operators, assignments, `instanceof`, `=>` and the `=` of a default or a constant:
 * one on each side, unless the operator sits at a line break. An assignment, `=` and the `=>` of an array item,
 * a `match` arm, a `yield` or a `foreach` stay on the line of what is before them unless that spans several lines or
 * the line would grow wider than the line length of the style, what follows them may begin below, unless it spans
 * several lines and `multiline.expressionStart` of the assignment, the array item or the arrow function says so, and
 * `instanceof` stays on the line of both its operands. What follows a comparison stays on the line of the operator,
 * unless the line would grow wider than the line length, and a comparison ending a line moves to the start of the next
 * one where `multiline.operatorPosition.comparison` says so and the joined line would be too wide; the lines around the
 * other operators are the matter of `MultilineBinaryOperatorRule` and `MultilineConditionRule`. Whitespace
 * wider than a space aligns a column of assignments or of array items, and `spacing.alignment.assignment` says
 * which of it stays. Concatenation is the matter of `ConcatenationSpacingRule`.
 */
#[RuleInfo(Stage::Formatting, analyses: [IndentationSnapshot::class])]
final class BinaryOperatorSpacingRule extends GapRule
{
	private const JoinedOperators = ['==', '!=', '<>', '===', '!==', '<', '<=', '>', '>=', '<=>'];
	private const Spacing = 'spacing.binaryOperator';
	private const Alignment = 'spacing.alignment.assignment';
	private const Position = 'multiline.operatorPosition.comparison';
	private const ExpressionStart = 'multiline.expressionStart.';

	/** the places of `multiline.expressionStart` the rule has => the operator after which an expression begins */
	private const ExpressionPlaces = [
		'assignment' => 'the `=` of an assignment, a constant, a property or a default',
		'arrayItem' => 'the `=>` of an array item',
		'arrowFunction' => 'the `=>` of an arrow function',
	];

	/** the space around an operator, null where it is kept */
	private ?Claim $claim;

	/** the space around an operator joined to the line of what is beside it, null where it is kept */
	private ?Claim $joined;

	/** where a comparison at a line break stands, null where it is kept */
	private ?string $position = null;

	/** the line before a comparison moved to the other side of its line break, null where it stays */
	private ?Claim $moved;

	/** what follows a moved comparison on its line, and on the next one */
	private Claim $afterMoved;
	private Claim $breakAfterMoved;

	/** @var array<string, ?Claim>  place => an expression spanning lines on the line of its operator, null where kept */
	private array $expressionStart = [];


	public static function getDecisions(): array
	{
		$decisions = [
			new Decision(
				self::Spacing,
				new Shapes(['spaced' => ['$a + $b', 'a single space around']]),
				'The spaces around a binary operator, an assignment, `instanceof`, `=>` and the `=` of a default included, `.` being `spacing.concatenation`; at a line break an operator takes no space on the side of the break, an assignment and `=>` stay on the line of what is before them, and `instanceof` and a comparison keep what follows them on their line, unless the line would grow too wide',
			),
			new Decision(
				self::Alignment,
				Domain::alignment(),
				'Which whitespace wider than a single space around an operator stays, aligning a column of assignments `=` or of array items `=>`; alignment is never made',
				kind: DecisionKind::Parameter,
				default: 'spaces',
			),
			new Decision(
				self::Position,
				Domain::operatorPosition(),
				'Where a comparison stands at a line break: one ending its line moves only where the line joined after it would be too wide, one opening its line joins the line before it, what follows staying on its line unless it would be too wide',
			),
		];
		foreach (self::ExpressionPlaces as $place => $what) {
			$decisions[] = new Decision(
				self::ExpressionStart . $place,
				Domain::placement('sameLine'),
				"Where an expression spanning several lines after $what begins",
			);
		}

		return $decisions;
	}


	public function configure(Values $values): void
	{
		$this->claim = $values->isKept(self::Spacing) ? null : (match ($values->get(self::Alignment)->getWord()) {
			'forbidden' => Claim::singleSpace(),
			'spaces' => Claim::atLeastOneSpace(),
			'tabs' => Claim::singleSpaceOrTabs(),
			default => Claim::atLeastOneSpaceOrTabs(),
		})->withDecision(self::Spacing);
		$this->joined = $this->claim === null ? null : new Claim($this->claim->space, line: Line::Same, decision: self::Spacing);
		$this->position = $values->find(self::Position)?->getWord();
		$this->moved = match ($this->position) {
			null => null,
			'lineEnd' => new Claim($this->claim?->space, line: Line::Same, decision: self::Position),
			default => Claim::nextLine()->withDecision(self::Position),
		};
		$this->afterMoved = new Claim($this->claim?->space, line: Line::Same, decision: self::Position);
		$this->breakAfterMoved = Claim::nextLine()->withDecision(self::Position);
		foreach (array_keys(self::ExpressionPlaces) as $place) {
			$path = self::ExpressionStart . $place;
			$this->expressionStart[$place] = $values->isKept($path) ? null : new Claim(Space::Single, line: Line::Same, decision: $path);
		}
	}


	public function getClaims(): array
	{
		$assignment = [$this->claimBeforeAssignment(...), fn(Gap $gap) => $this->claimAfterAssignment($gap, 'assignment')];
		$arrow = ['doubleArrow' => [$this->claimBeforeAssignment(...), $this->claim]];
		// the equals of declare(strict_types=1) is `DeclareSpacingRule`'s
		$declared = fn(Gap $gap) => $gap->token->parent instanceof DeclareItemNode;
		return [
			BinaryOpNode::class => ['operator' => [$this->claimBeforeOperator(...), $this->claimAfterOperator(...)]],
			AssignmentNode::class => ['equals' => $assignment],
			CombinedAssignmentNode::class => ['operator' => $assignment],
			InstanceofNode::class => ['instanceofKeyword' => [$this->joined, $this->joined]],
			ArrayItemNode::class => ['doubleArrow' => [
				$this->claimBeforeAssignment(...),
				fn(Gap $gap) => $this->claimAfterAssignment($gap, 'arrayItem'),
			]],
			MatchArmNode::class => $arrow,
			YieldNode::class => $arrow,
			ForeachNode::class => $arrow,
			// the double arrow of a short closure or of a hook may begin a line of its own
			'*' => ['doubleArrow' => [
				$this->claim,
				fn(Gap $gap) => $gap->token->parent instanceof ArrowFunctionNode
					? $this->claimAfterAssignment($gap, 'arrowFunction')
					: $this->claim,
			], 'equals' => [
				fn(Gap $gap): ?Claim => $declared($gap) ? null : $this->claimBeforeAssignment($gap),
				fn(Gap $gap): ?Claim => $declared($gap) ? null : $this->claimAfterAssignment($gap, 'assignment'),
			]],
		];
	}


	private function claimBeforeOperator(Gap $gap): ?Claim
	{
		return match (true) {
			$gap->token->text === '.' => null, // `ConcatenationSpacingRule`
			$this->findMove($gap) !== null => $this->moved,
			default => $this->claim,
		};
	}


	/**
	 * What follows a comparison stays on its line, unless a comment stands between
	 * them or the joined line would be wider than the line length.
	 */
	private function claimAfterOperator(Gap $gap): ?Claim
	{
		$token = $gap->token;
		$next = $token->getNext();
		$move = $this->findMove($gap);
		return match (true) {
			$token->text === '.' => null, // `ConcatenationSpacingRule`
			$move === Line::Same => $this->afterMoved,
			$move === Line::Next => $this->breakAfterMoved,
			$this->claim === null => null,
			$next === null || !$token->is(self::JoinedOperators) || $token->hasCommentUpTo($next) => $this->claim,
			$next->startsLine() && self::isJoinedLineTooWide($gap, $token, $next) !== false => $this->claim,
			default => $this->joined,
		};
	}


	/**
	 * The line what follows a comparison takes once it moves to the side of its line break the position says, decided
	 * once per pass about the operation, because the break put beside it changes the width of its line: one ending its
	 * line moves to the start of the next one only where the joined line would be too wide, one opening its line moves
	 * to the end of the line before, what follows it joining that line where it fits; null where it stays.
	 */
	private function findMove(Gap $gap): ?Line
	{
		$token = $gap->token;
		$operation = $token->parent;
		$position = $this->position;
		if (
			$position === null
			|| !$operation instanceof BinaryOpNode
			|| NodeHelpers::findOperatorPlace($operation) !== 'comparison' // the others are `MultilineBinaryOperatorRule`'s
		) {
			return null;
		}

		return $gap->once($operation, function () use ($token, $gap, $position): ?Line {
			$previous = $token->getPrevious();
			$next = $token->getNext();
			if ($previous === null || $next === null || !NodeHelpers::isOperatorMisplaced($token, $position)) {
				return null;
			} elseif ($position === 'lineEnd') {
				$tooWide = self::isJoinedLineTooWide($gap, $previous, $token);
				return $tooWide === null ? null : ($tooWide ? Line::Next : Line::Same);
			}

			return self::isJoinedLineTooWide($gap, $token, $next) === true ? Line::Same : null;
		});
	}


	/**
	 * The operator joins the line of what is before it, unless that spans several lines itself, as the conditions
	 * of a `match` arm may, or the joined line would be wider than the line length.
	 */
	private function claimBeforeAssignment(Gap $gap): ?Claim
	{
		$token = $gap->token;
		$previous = $token->getPrevious();
		if ($this->claim === null || $previous === null || !$token->startsLine()) {
			return $this->joined;
		}

		$first = $token->parent?->getFirstToken();
		if ($first !== null && $first->getCurrentLine() !== $previous->getCurrentLine()) {
			return $this->claim;
		}

		return self::isJoinedLineTooWide($gap, $previous, $token) !== false ? $this->claim : $this->joined;
	}


	/**
	 * What follows `=` or `=>` may begin below it, except an expression spanning several lines where
	 * `multiline.expressionStart` of the place says it begins on the line of the operator.
	 */
	private function claimAfterAssignment(Gap $gap, string $place): ?Claim
	{
		$sameLine = $this->expressionStart[$place];
		$token = $gap->token;
		$next = $token->getNext();
		if ($sameLine === null || $next === null || !$next->startsLine() || $token->hasCommentUpTo($next)) {
			return $this->claim;
		}

		// the expression is the outermost node beginning with the token after the operator
		$expression = null;
		for ($node = $next->parent; $node !== null && $node !== $token->parent && $node->getFirstToken() === $next; $node = $node->parent) {
			$expression = $node;
		}

		return $expression?->isMultiLine() ? $sameLine : $this->claim;
	}


	/**
	 * Whether the line of the second token, joined to the line of the first one with a space between them, would
	 * be wider than the line length of the style; null while the line of the first one is not indented yet, which
	 * leaves the line as it is until a pass later measures it.
	 */
	private static function isJoinedLineTooWide(Gap $gap, Token $first, Token $second): ?bool
	{
		$style = $gap->style;
		if ($style->maxLineLength === null) {
			return false;
		} elseif (!NodeHelpers::isLineInPlace($gap, $first)) {
			return null;
		}

		$phpSyntax = $style->toPhpSyntax();
		$rest = Indentation::measureLineWidth($second, $phpSyntax) - Indentation::advance(0, $second->getIndentation(), $phpSyntax);
		return Indentation::measureLineWidth($first, $phpSyntax) + 1 + $rest > $style->maxLineLength;
	}
}
