<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use DressCode\Engine\Gaps\Memory;
use PhpSyntax\{Node, Token};


/**
 * The gap a closure of a gap rule is asked about: the token at its edge on the side of the claim, the value
 * of the slot or the item or separator the claim was made for and, in a list, its index, with the style of
 * the file. A claim that depends on the shape of the text must give every gap of a construct the same answer
 * whatever the engine has done to the gaps before it: once() keeps a decision about a node for the pass.
 */
final readonly class Gap
{
	/** @internal */
	public function __construct(
		public Token $token,
		public Node|Token $value,
		public ?int $index,
		public Style $style,
		private Rule $rule,
		private Memory $memory,
		/** @var list<Rule> */
		private array $rules,
	) {
	}


	/**
	 * What the rule decides about the node, made from the shape the node has when the first of its gaps is
	 * reached and answered the same at every gap after it until the pass ends. A decision other than null, false
	 * or an empty array makes the claims following from it one violation of the node.
	 * @template T
	 * @param \Closure(): T $decide
	 * @return T
	 */
	public function once(Node $node, \Closure $decide): mixed
	{
		return $this->memory->once($this->rule, $node, $decide);
	}


	/**
	 * The rule of the class as it is configured for this file, as RuleContext::findRule() gives it; null when that
	 * rule does not run on the file.
	 * @template T of Rule
	 * @param  class-string<T>  $class
	 * @return ?T
	 */
	public function findRule(string $class): ?Rule
	{
		return array_find($this->rules, fn(Rule $rule) => $rule instanceof $class);
	}
}
