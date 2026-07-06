<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Indentation, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DeclareItemNode, MatchArmNode};
use PhpSyntax\Nodes\Expression\{AssignmentNode, BinaryOpNode, CombinedAssignmentNode, InstanceofNode, YieldNode};
use PhpSyntax\Nodes\Statement\ForeachNode;


/**
 * Spaces around binary operators, assignments, `instanceof`, `=>` and the `=` of a default or a constant:
 * one on each side, unless the operator sits at a line break. An assignment, `=` and the `=>` of an array item,
 * a match arm, a yield or a foreach stay on the line of what is before them unless that spans several lines or
 * the line would grow wider than the line length of the style, what follows them may begin below, and
 * `instanceof` stays on the line of both its operands. What follows a comparison, a bitwise operator or a shift
 * stays on the line of the operator, unless the line would grow wider than the line length. An operator ending
 * a line may be moved to the start of the next one: a comparison, a bitwise operator or a shift only where the
 * joined line would be too wide, and a boolean operator chaining a condition never, because
 * dresscode/multi-line-condition places it. Whitespace wider than a space aligns a column of assignments or of
 * array items, and the alignment option says which of it stays. Concatenation is the matter of
 * dresscode/concat-spacing.
 */
#[RuleInfo(
	'dresscode/binary-operator-spacing',
	Stage::Formatting,
	description: 'Puts spaces around binary operators',
)]
final class BinaryOperatorSpacingRule extends GapRule implements ConfigurableRule
{
	private const JoinedOperators = ['==', '!=', '<>', '===', '!==', '<', '<=', '>', '>=', '<=>', '&', '|', '^', '<<', '>>'];

	private Claim $claim;
	private Claim $joined;
	private string $operatorPosition;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'alignment' => Expect::anyOf('none', 'spaces', 'tabs', 'keep')->default('spaces')
				->description('Which alignment around an operator stays: none collapses it to a single space, spaces and tabs keep the one written with them, keep keeps any'),
			'operatorPosition' => Expect::anyOf('keep', 'start')->default('keep')
				->description('Where a binary operator at a line break stands, except a boolean operator chaining a condition, which dresscode/multi-line-condition places: keep leaves it where it is, start moves one ending a line to the start of the next unless a comment follows it or the rule joins the lines'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = match ($options['alignment']) {
			'none' => Claim::single(),
			'spaces' => Claim::atLeastSingle(),
			'tabs' => Claim::singleOrTabs(),
			default => Claim::atLeastSingleOrTabs(),
		};
		$this->joined = new Claim($this->claim->space, line: Line::Same);
		$this->operatorPosition = $options['operatorPosition'];
	}


	public function getClaims(): array
	{
		$assignment = [$this->claimBeforeAssignment(...), $this->claim];
		$arrow = ['doubleArrow' => $assignment];
		// the equals of declare(strict_types=1) is dresscode/declare-spacing's
		$declared = fn(Gap $gap) => $gap->token->parent instanceof DeclareItemNode;
		return [
			BinaryOpNode::class => ['operator' => [$this->claimBeforeOperator(...), $this->claimAfterOperator(...)]],
			AssignmentNode::class => ['operator' => $assignment],
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
			$gap->token->text === '.' => null, // dresscode/concat-spacing
			$this->isMovedToStart($gap) => Claim::nextLine(),
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
			$token->text === '.' => null, // dresscode/concat-spacing
			$this->isMovedToStart($gap) => $this->joined,
			$next === null || !$token->is(...self::JoinedOperators) || $token->hasCommentUpTo($next) => $this->claim,
			$next->startsLine() && self::isJoinedLineTooWide($gap, $token, $next) !== false => $this->claim,
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
		return $this->operatorPosition === 'start'
			&& $operation instanceof BinaryOpNode
			&& NodeHelpers::findConditionStatement($operation) === null // dresscode/multi-line-condition
			&& $gap->once($operation, function () use ($token, $gap): bool {
				$next = $token->getNext();
				return $next !== null
					&& NodeHelpers::isLineBrokenAfter($token)
					&& (!$token->is(...self::JoinedOperators) || self::isJoinedLineTooWide($gap, $token, $next) === true);
			});
	}


	/**
	 * The operator joins the line of what is before it, unless that spans several lines itself, as the conditions
	 * of a match arm may, or the joined line would be wider than the line length.
	 */
	private function claimBeforeAssignment(Gap $gap): Claim
	{
		$token = $gap->token;
		$previous = $token->getPrevious();
		if ($previous === null || !$token->startsLine()) {
			return $this->joined;
		}

		$first = $token->parent?->getFirstToken();
		if ($first !== null && $first->getLine() !== $previous->getLine()) {
			return $this->claim;
		}

		return self::isJoinedLineTooWide($gap, $previous, $token) !== false ? $this->claim : $this->joined;
	}


	/**
	 * Whether the line of the second token, joined to the line of the first one with a space between them, would
	 * be wider than the line length of the style; null while the line of the first one is not indented yet, which
	 * leaves the line as it is until a pass later measures it.
	 */
	private static function isJoinedLineTooWide(Gap $gap, Token $first, Token $second): ?bool
	{
		$style = $gap->style;
		if ($style->lineLength === null) {
			return false;
		} elseif (!NodeHelpers::isLineInPlace($gap, $first)) {
			return null;
		}

		$phpSyntax = $style->toPhpSyntax();
		$rest = $second->getLineWidth($phpSyntax) - Indentation::advance(0, $second->getIndentation(), $phpSyntax);
		return $first->getLineWidth($phpSyntax) + 1 + $rest > $style->lineLength;
	}
}
