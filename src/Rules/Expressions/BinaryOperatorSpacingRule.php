<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\{Indentation, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DeclareItemNode, MatchArmNode};
use PhpSyntax\Nodes\Expression\{AssignmentNode, BinaryOpNode, CombinedAssignmentNode, InstanceofNode, YieldNode};
use PhpSyntax\Nodes\Statement\ForeachNode;


/**
 * Spaces around binary operators, assignments, `instanceof`, `=>` and the `=` of a default or a constant:
 * one on each side, unless the operator sits at a line break. An assignment, `=` and the `=>` of an array item,
 * a `match` arm, a `yield` or a `foreach` stay on the line of what is before them unless that spans several lines or
 * the line would grow wider than the line length of the style, what follows them may begin below, and
 * `instanceof` stays on the line of both its operands. What follows a comparison, a bitwise operator or a shift
 * stays on the line of the operator, unless the line would grow wider than the line length. An operator ending
 * a line may be moved to the start of the next one, as `multiline.operatorPosition.binary` says: a comparison, a
 * bitwise operator or a shift only where the joined line would be too wide. Whitespace wider than a space aligns
 * a column of assignments or of array items, and `spacing.binaryOperatorAlignment` says which of it stays.
 * Concatenation is the matter of `ConcatenationSpacingRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class BinaryOperatorSpacingRule extends GapRule
{
	private const JoinedOperators = ['==', '!=', '<>', '===', '!==', '<', '<=', '>', '>=', '<=>', '&', '|', '^', '<<', '>>'];
	private const Spacing = 'spacing.binaryOperator';
	private const Alignment = 'spacing.binaryOperatorAlignment';
	private const Position = 'multiline.operatorPosition.binary';

	/** the space around an operator, null where it is kept */
	private ?Claim $claim;

	/** the space around an operator joined to the line of what is beside it, null where it is kept */
	private ?Claim $joined;

	/** the break in front of an operator moved to the start of the next line, null where it stays */
	private ?Claim $moved;

	/** what follows an operator moved to the start of the next line */
	private Claim $afterMoved;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Spacing, new Shapes(['spaced' => ['$a + $b', 'a single space around']]), 'The spaces around a binary operator, an assignment, `instanceof`, `=>` and the `=` of a default included, `.` being `spacing.concatenation`; at a line break an operator takes no space on the side of the break, an assignment and `=>` stay on the line of what is before them, and `instanceof`, a comparison, a bitwise operator and a shift keep what follows them on their line, unless the line would grow too wide'),
			new Decision(self::Alignment, Domain::alignment(), 'Which whitespace wider than a single space around an operator stays, aligning a column of assignments or of array items; alignment is never made', parameter: true, default: 'spaces'),
			new Decision(self::Position, Domain::lineStart(), 'Where a binary operator other than `.` stands at a line break: a comparison, a bitwise operator or a shift moves only where the line joined after it would be too wide'),
		];
	}


	public function configure(Values $values): void
	{
		$this->claim = $values->isKept(self::Spacing) ? null : (match ($values->get(self::Alignment)->getWord()) {
			'none' => Claim::singleSpace(),
			'spaces' => Claim::atLeastOneSpace(),
			'tabs' => Claim::singleSpaceOrTabs(),
			default => Claim::atLeastOneSpaceOrTabs(),
		})->withDecision(self::Spacing);
		$this->joined = $this->claim === null ? null : new Claim($this->claim->space, line: Line::Same, decision: self::Spacing);
		$this->moved = $values->isKept(self::Position) ? null : Claim::nextLine()->withDecision(self::Position);
		$this->afterMoved = new Claim($this->claim?->space, line: Line::Same, decision: self::Position);
	}


	public function getClaims(): array
	{
		$assignment = [$this->claimBeforeAssignment(...), $this->claim];
		$arrow = ['doubleArrow' => $assignment];
		// the equals of declare(strict_types=1) is `DeclareSpacingRule`'s
		$declared = fn(Gap $gap) => $gap->token->parent instanceof DeclareItemNode;
		return [
			BinaryOpNode::class => ['operator' => [$this->claimBeforeOperator(...), $this->claimAfterOperator(...)]],
			AssignmentNode::class => ['equals' => $assignment],
			CombinedAssignmentNode::class => ['operator' => $assignment],
			InstanceofNode::class => ['instanceofKeyword' => [$this->joined, $this->joined]],
			ArrayItemNode::class => $arrow,
			MatchArmNode::class => $arrow,
			YieldNode::class => $arrow,
			ForeachNode::class => $arrow,
			// the double arrow of a short closure or of a hook may begin a line of its own
			'*' => ['doubleArrow' => [$this->claim, $this->claim], 'equals' => [
				fn(Gap $gap): ?Claim => $declared($gap) ? null : $this->claimBeforeAssignment($gap),
				fn(Gap $gap): ?Claim => $declared($gap) ? null : $this->claim,
			]],
		];
	}


	private function claimBeforeOperator(Gap $gap): ?Claim
	{
		return match (true) {
			$gap->token->text === '.' => null, // `ConcatenationSpacingRule`
			$this->isMovedToStart($gap) => $this->moved,
			default => $this->claim,
		};
	}


	/**
	 * What follows a comparison, a bitwise operator or a shift stays on its line, unless a comment stands between
	 * them or the joined line would be wider than the line length.
	 */
	private function claimAfterOperator(Gap $gap): ?Claim
	{
		$token = $gap->token;
		$next = $token->getNext();
		return match (true) {
			$token->text === '.' => null, // `ConcatenationSpacingRule`
			$this->isMovedToStart($gap) => $this->afterMoved,
			$this->claim === null => null,
			$next === null || !$token->is(self::JoinedOperators) || $token->hasCommentUpTo($next) => $this->claim,
			$next->startsLine() && self::isJoinedLineTooWide($gap, $token, $next) => $this->claim,
			default => $this->joined,
		};
	}


	/**
	 * Whether the operator ends its line and goes to the start of the next one, decided once per pass about the
	 * operation, because the break put in front of it changes the width of its line; one whose line is joined
	 * moves only where the joined line would be too wide.
	 */
	private function isMovedToStart(Gap $gap): bool
	{
		$token = $gap->token;
		$operation = $token->parent;
		return $this->moved !== null
			&& $operation instanceof BinaryOpNode
			&& $gap->once($operation, function () use ($token, $gap): bool {
				$next = $token->getNext();
				return $next !== null
					&& $token->isFollowedByLineEnding()
					&& (!$token->is(self::JoinedOperators) || self::isJoinedLineTooWide($gap, $token, $next));
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

		return self::isJoinedLineTooWide($gap, $previous, $token) ? $this->claim : $this->joined;
	}


	/**
	 * Whether the line of the second token, joined to the line of the first one with a space between them, would
	 * be wider than the line length of the style.
	 */
	private static function isJoinedLineTooWide(Gap $gap, Token $first, Token $second): bool
	{
		$style = $gap->style;
		if ($style->maxLineLength === null) {
			return false;
		}

		$phpSyntax = $style->toPhpSyntax();
		$rest = Indentation::measureLineWidth($second, $phpSyntax) - Indentation::advance(0, $second->getIndentation(), $phpSyntax);
		return Indentation::measureLineWidth($first, $phpSyntax) + 1 + $rest > $style->maxLineLength;
	}
}
