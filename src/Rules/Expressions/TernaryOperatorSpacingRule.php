<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * Whitespace around `?` and `:` of a ternary, and around `?:` as a whole when the middle operand is left out:
 * at least one space, as the standards word it, or exactly one; an operator at a line break is left alone, unless
 * it ends the line and `multiline.operatorPosition.ternary` moves it to the start of the next one.
 */
#[RuleInfo(Stage::Formatting)]
final class TernaryOperatorSpacingRule extends GapRule
{
	private const Spacing = 'spacing.ternary';
	private const Alignment = 'spacing.ternaryAlignment';
	private const Position = 'multiline.operatorPosition.ternary';

	/** the space around an operator, null where it is kept */
	private ?Claim $claim;

	/** no space inside `?:`, null where it is kept */
	private ?Claim $short;

	private bool $operatorsAtStart = false;

	/** what follows an operator moved to the start of the next line */
	private Claim $afterMoved;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Spacing, new Shapes(['spaced' => ['$a ? $b : $c', 'whitespace around `?` and `:`']]), 'The whitespace around `?` and `:` of a ternary, and around `?:` as a whole, unless the operator sits at a line break'),
			new Decision(self::Alignment, Domain::alignment('none', 'any'), 'Whether more spaces around `?` and `:` stay', parameter: true, default: 'any'),
			new Decision(self::Position, Domain::lineStart(), 'Where `?`, `:` and `?:` of a ternary stand at a line break'),
		];
	}


	public function configure(Values $values): void
	{
		$spacing = !$values->isKept(self::Spacing);
		$this->claim = $spacing
			? ($values->get(self::Alignment)->getWord() === 'none' ? Claim::singleSpace() : Claim::atLeastOneSpace())->withDecision(self::Spacing)
			: null;
		$this->short = $spacing ? Claim::noSpace()->withDecision(self::Spacing) : null;
		$this->operatorsAtStart = !$values->isKept(self::Position);
		$this->afterMoved = new Claim($this->claim?->space, line: Line::Same, decision: self::Position);
	}


	public function getClaims(): array
	{
		$short = fn(Gap $gap) => $gap->token->parent instanceof TernaryNode && $gap->token->parent->then === null;
		// the break in front of an operator is claimed after the operand before it
		return [
			TernaryNode::class => [
				'condition' => [null, fn(Gap $gap) => $this->claimBreakBefore($gap, 0)],
				'then' => [null, fn(Gap $gap) => $this->claimBreakBefore($gap, 1)],
				'question' => [$this->claim, fn(Gap $gap) => $short($gap) ? $this->short : $this->claimAfter($gap, 0)],
				'colon' => [fn(Gap $gap) => $short($gap) ? $this->short : $this->claim, fn(Gap $gap) => $this->claimAfter($gap, 1)],
			],
		];
	}


	/** The line break in front of the operator, `?` as 0 and `:` as 1, after the operand before it. */
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
		return new Claim(line: Line::Next, because: "the `$text` operator ends its line", decision: self::Position);
	}


	/** What follows the operator, `?` as 0 and `:` as 1, joins its line when the operator moves there. */
	private function claimAfter(Gap $gap, int $operator): ?Claim
	{
		return $this->decideMoves($gap)[$operator] ? $this->afterMoved : $this->claim;
	}


	/**
	 * Whether `?` and whether `:` end their line and go to the start of the next one, `?:` as a whole by its colon,
	 * decided once per pass about the ternary.
	 * @return array{bool, bool}
	 */
	private function decideMoves(Gap $gap): array
	{
		$ternary = $gap->value->parent;
		if (!$this->operatorsAtStart || !$ternary instanceof TernaryNode) {
			return [false, false];
		}

		return $gap->once($ternary, function () use ($ternary): ?array {
			$moves = [
				($ternary->then === null ? $ternary->colon : $ternary->question)->isFollowedByLineEnding(),
				$ternary->colon->isFollowedByLineEnding(),
			];
			// no decision where nothing moves, so that the spaces around the operators stay violations of their own
			return $moves === [false, false] ? null : $moves;
		}) ?? [false, false];
	}
}
