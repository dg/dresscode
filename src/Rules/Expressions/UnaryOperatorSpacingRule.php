<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\{PostfixOpNode, PrefixOpNode, UnaryOpNode, VariableNode};
use function count;


/**
 * No whitespace between a unary operator and its operand, which stay on one line: `-$b`, `$i++`, nor inside a
 * variable variable, `$$a` and `${'a'}`. After `!` the whitespace is what `spacing.logicalNot` says, where a tolerance
 * passes each of its shapes as written.
 */
#[RuleInfo(Stage::Formatting)]
final class UnaryOperatorSpacingRule extends GapRule
{
	private const Unary = 'spacing.unaryOperator';
	private const LogicalNot = 'spacing.logicalNot';

	private bool $unary = true;

	/** @var list<string>  the shapes after `!` that pass, the first written; none where it is kept */
	private array $logicalNot = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Unary,
				new Shapes(['compact' => ['-$x', 'no space after the operator']]),
				'The whitespace between a unary operator other than `!` and its operand, which stay on one line, and inside a variable variable, `$$a` and `${\'a\'}`',
			),
			new Decision(self::LogicalNot, new Shapes([
				'compact' => ['!$x', 'no space after the operator'],
				'spaced' => ['! $x', 'a single space after the operator'],
			], tolerance: true), 'The whitespace between `!` and its operand, which stay on one line'),
		];
	}


	public function configure(Values $values): void
	{
		$this->unary = !$values->isKept(self::Unary);
		$this->logicalNot = $values->isKept(self::LogicalNot) ? [] : $values->get(self::LogicalNot)->getShapes();
	}


	public function getClaims(): array
	{
		$claims = [];
		$hug = null;
		if ($this->unary) {
			$hug = new Claim(Space::None, line: Line::Same, decision: self::Unary);
			$claims[VariableNode::class] = [
				'dollar' => [null, $hug],
				'openBrace' => [null, $hug],
				'closeBrace' => [$hug, null],
			];
			$claims[PrefixOpNode::class] = ['operator' => [null, $hug]];
			$claims[PostfixOpNode::class] = ['operator' => [$hug, null]];
		}

		$not = $this->claimLogicalNot();
		if ($hug !== null || $not !== null) {
			$claims[UnaryOpNode::class] = ['operator' => [
				null,
				fn(Gap $gap) => $gap->token->text === '!' ? ($not instanceof \Closure ? $not($gap) : $not) : $hug,
			]];
		}

		return $claims;
	}


	/**
	 * The claim after `!`: the one of its shape, or of a tolerance the shape written where it passes and the first
	 * where none does; null where it is kept.
	 * @return Claim|\Closure(Gap): Claim|null
	 */
	private function claimLogicalNot(): Claim|\Closure|null
	{
		$claims = [];
		foreach ($this->logicalNot as $shape) {
			$claims[$shape] = new Claim($shape === 'compact' ? Space::None : Space::Single, line: Line::Same, decision: self::LogicalNot);
		}

		if ($claims === []) {
			return null;
		}

		$first = reset($claims);
		return count($claims) === 1 ? $first : fn(Gap $gap) => match ($gap->token->getTrailingSpace()) {
			'' => $claims['compact'] ?? $first,
			' ' => $claims['spaced'] ?? $first,
			default => $first,
		};
	}
}
