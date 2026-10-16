<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, DecisionKind, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Shapes;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * Whitespace around `?` and `:` of a ternary, and around `?:` as a whole when the middle operand is left out:
 * at least one space, as the standards word it, or exactly one; an operator at a line break is left alone, unless
 * it stands on the other side of the break than `multiline.operatorPosition.ternary` says, which moves it there.
 */
#[RuleInfo(Stage::Formatting, decisions: ['multiline.operatorPosition.ternary'])]
final class TernaryOperatorSpacingRule extends GapRule
{
	private const Spacing = 'spacing.ternary';
	private const Alignment = 'spacing.alignment.ternary';
	private const Position = 'multiline.operatorPosition.ternary';

	/** the space around an operator, null where it is kept */
	private ?Claim $claim;

	/** no space inside `?:`, null where it is kept */
	private ?Claim $short;

	/** where `?` and `:` at a line break stand, null where it is kept */
	private ?string $position = null;

	/** what follows an operator moved to the other side of its line break */
	private Claim $afterMoved;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Spacing,
				new Shapes(['spaced' => ['$a ? $b : $c', 'whitespace around `?` and `:`']]),
				'The whitespace around `?` and `:` of a ternary, and around `?:` as a whole, unless the operator sits at a line break',
			),
			new Decision(
				self::Alignment,
				Domain::alignment('forbidden', 'optional'),
				'The whitespace wider than a single space around `?` and `:`, aligning a column; alignment is never made',
				kind: DecisionKind::Parameter,
				default: 'optional',
			),
		];
	}


	public function configure(Values $values): void
	{
		$spacing = !$values->isKept(self::Spacing);
		$this->claim = $spacing
			? ($values->get(self::Alignment)->getWord() === 'forbidden' ? Claim::singleSpace() : Claim::atLeastOneSpace())->withDecision(
				self::Spacing,
			)
			: null;
		$this->short = $spacing ? Claim::noSpace()->withDecision(self::Spacing) : null;
		$this->position = $values->find(self::Position)?->getWord();
		$this->afterMoved = $this->position === 'lineEnd'
			? new Claim(line: Line::Next, decision: self::Position)
			: new Claim($this->claim?->space, line: Line::Same, decision: self::Position);
	}


	public function getClaims(): array
	{
		$short = fn(Gap $gap) => $gap->token->parent instanceof TernaryNode && $gap->token->parent->then === null;
		// the break in front of an operator is claimed after the operand before it, because the claim before the
		// operator is `MultilineTernaryRule`'s
		return [
			TernaryNode::class => [
				'condition' => [null, fn(Gap $gap) => $this->claimBreakBefore($gap, 0)],
				'then' => [null, fn(Gap $gap) => $this->claimBreakBefore($gap, 1)],
				'question' => [$this->claim, fn(Gap $gap) => $short($gap) ? $this->short : $this->claimAfter($gap, 0)],
				'colon' => [fn(Gap $gap) => $short($gap) ? $this->short : $this->claim, fn(Gap $gap) => $this->claimAfter($gap, 1)],
			],
		];
	}


	/** The line before the operator, `?` as 0 and `:` as 1, after the operand before it. */
	private function claimBreakBefore(Gap $gap, int $operator): ?Claim
	{
		$ternary = $gap->value->parent;
		if (!$ternary instanceof TernaryNode || !$this->decideMoves($gap)[$operator]) {
			return null;
		}

		$text = match (true) {
			$operator === 1 => ':',
			$ternary->then === null => '?:',
			default => '?',
		};
		return $this->position === 'lineEnd'
			? new Claim(line: Line::Same, because: "the `$text` operator opens its line", decision: self::Position)
			: new Claim(line: Line::Next, because: "the `$text` operator ends its line", decision: self::Position);
	}


	/** What follows the operator, `?` as 0 and `:` as 1, joins its line when the operator moves there. */
	private function claimAfter(Gap $gap, int $operator): ?Claim
	{
		return $this->decideMoves($gap)[$operator] ? $this->afterMoved : $this->claim;
	}


	/**
	 * Whether `?` and whether `:` stand on the other side of their line break than the position says and go to that
	 * side, `?:` as a whole, decided once per pass about the ternary.
	 * @return array{bool, bool}
	 */
	private function decideMoves(Gap $gap): array
	{
		$ternary = $gap->value->parent;
		$position = $this->position;
		if ($position === null || !$ternary instanceof TernaryNode) {
			return [false, false];
		}

		return $gap->once($ternary, function () use ($ternary, $position): ?array {
			// `?:` breaks after its colon or before its question mark
			$short = $ternary->then === null;
			$first = NodeHelpers::isOperatorMisplaced(
				$short && $position === 'lineStart' ? $ternary->colon : $ternary->question,
				$position,
			);
			$moves = [$first, $short ? $first : NodeHelpers::isOperatorMisplaced($ternary->colon, $position)];
			// no decision where nothing moves, so that the spaces around the operators stay violations of their own
			return $moves === [false, false] ? null : $moves;
		}) ?? [false, false];
	}
}
