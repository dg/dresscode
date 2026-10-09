<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\{Names, Shapes};
use PhpSyntax\Nodes\Expression\{PostfixOpNode, PrefixOpNode, UnaryOpNode, VariableNode};
use function count;


/**
 * No whitespace between a unary operator and its operand, which stay on one line: `!$a`, `-$b`, `$i++`, nor
 * inside a variable variable, `$$a` and `${'a'}`. An operator `spacing.unaryOperator.withSpace` names keeps the
 * whitespace written beside it.
 */
#[RuleInfo(Stage::Formatting)]
final class UnaryOperatorSpacingRule extends GapRule
{
	private const WithSpace = 'spacing.unaryOperator.withSpace';

	/** @var list<string> */
	private array $withSpace = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('spacing.unaryOperator.after', new Shapes(['compact' => ['-$x', 'no space after the operator']]), 'The whitespace between a unary operator and its operand, which stay on one line, and inside a variable variable, `$$a` and `${\'a\'}`'),
			new Decision(self::WithSpace, new Names([
				'++' => 'increment',
				'--' => 'decrement',
				'!' => 'negation',
				'-' => 'minus',
				'+' => 'plus',
				'~' => 'bitwise negation',
				'@' => 'error suppression',
			]), 'The operators whose operand may stand apart from them, as written, `! $x`', parameter: true, default: []),
		];
	}


	public function configure(Values $values): void
	{
		$this->withSpace = $values->get(self::WithSpace)->getNames();
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$claims = [
			VariableNode::class => [
				'dollar' => [null, $hug],
				'openBrace' => [null, $hug],
				'closeBrace' => [$hug, null],
			],
		];
		$increment = $this->claimFor(['++', '--'], $hug);
		if ($increment !== null) {
			$claims[PrefixOpNode::class] = ['operator' => [null, $increment]];
			$claims[PostfixOpNode::class] = ['operator' => [$increment, null]];
		}

		$unary = $this->claimFor(['!', '-', '+', '~', '@'], $hug);
		if ($unary !== null) {
			$claims[UnaryOpNode::class] = ['operator' => [null, $unary]];
		}

		return $claims;
	}


	/**
	 * The claim on the operators of one kind of node: the plain one where the project allows none of them, null where
	 * it allows them all.
	 * @param  list<string>  $operators
	 * @return Claim|\Closure(Gap): ?Claim|null
	 */
	private function claimFor(array $operators, Claim $hug): Claim|\Closure|null
	{
		$allowed = array_values(array_intersect($operators, $this->withSpace));
		return match (count($allowed)) {
			0 => $hug,
			count($operators) => null,
			default => fn(Gap $gap) => in_array($gap->token->text, $allowed, true) ? null : $hug,
		};
	}
}
