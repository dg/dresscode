<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, GapRule, RuleInfo, Stage, Values};
use PhpSyntax\Nodes\{ArgumentListNode, CatchNode, ClosureUseListNode, ElseifNode};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode, EmptyNode, EvalNode, IssetNode, MatchNode, ParenthesizedNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode};
use PhpSyntax\Nodes\Statement\{
	DoWhileNode, ForeachNode, ForNode, FunctionNode, HaltCompilerNode, IfNode, SwitchNode, UnsetNode, WhileNode,
};


/**
 * No whitespace just inside parentheses on a line: `foo($a)`, `if ($a)`, not `foo( $a )`; the parentheses of
 * arguments, of parameters, of the `use` of a closure, of a control structure and of a grouping are each a place
 * of `spacing.padding`.
 */
#[RuleInfo(Stage::Formatting)]
final class ParenthesesSpacingRule extends GapRule
{
	/** a place of `spacing.padding` => the nodes whose parentheses it is, and what the decision says */
	private const Places = [
		'argument' => [
			[ArgumentListNode::class, IssetNode::class, EmptyNode::class, EvalNode::class, UnsetNode::class, HaltCompilerNode::class],
			'The whitespace inside the parentheses of the arguments of a call, of `new` and of an attribute, and of `isset()`, `unset()`, `empty()` and `eval()`',
		],
		'parameter' => [
			[FunctionNode::class, MethodNode::class, ClosureNode::class, ArrowFunctionNode::class, PropertyHookNode::class],
			'The whitespace inside the parentheses of the parameters of a function, a method, a closure, an arrow function and a property hook',
		],
		'closureUse' => [[ClosureUseListNode::class], 'The whitespace inside the parentheses of the `use` of a closure'],
		'controlStructure' => [
			[
				IfNode::class, ElseifNode::class, WhileNode::class, DoWhileNode::class, ForNode::class, ForeachNode::class,
				SwitchNode::class, CatchNode::class, MatchNode::class,
			],
			'The whitespace inside the parentheses of `if`, `elseif`, `while`, `for`, `foreach`, `switch`, `match` and `catch`',
		],
		'grouping' => [[ParenthesizedNode::class], 'The whitespace inside the parentheses grouping an expression, `($a + $b) * $c`'],
	];

	/** @var array<string, ?Claim>  place => no space inside, null where it is kept */
	private array $claims = [];


	public static function getDecisions(): array
	{
		return array_map(
			fn(string $place, array $entry) => new Decision("spacing.padding.$place", Domain::state(), $entry[1]),
			array_keys(self::Places),
			self::Places,
		);
	}


	public function configure(Values $values): void
	{
		foreach (array_keys(self::Places) as $place) {
			$path = "spacing.padding.$place";
			$this->claims[$place] = $values->isKept($path) ? null : Claim::noSpace()->withDecision($path);
		}
	}


	public function getClaims(): array
	{
		$claims = [];
		foreach (self::Places as $place => [$classes]) {
			$none = $this->claims[$place] ?? null;
			foreach ($classes as $class) {
				$claims[$class] = ['openParen' => [null, $none], 'closeParen' => [$none, null]];
			}
		}

		return $claims;
	}
}
