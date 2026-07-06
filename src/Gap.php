<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Engine\Gaps\Memory;
use PhpSyntax\Node;
use PhpSyntax\Style;
use PhpSyntax\Token;


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
	) {
	}


	/**
	 * What the rule decides about the node, made from the shape the node has when the first of its gaps is
	 * reached and answered the same at every gap after it until the pass ends.
	 * @template T
	 * @param \Closure(): T $decide
	 * @return T
	 */
	public function once(Node $node, \Closure $decide): mixed
	{
		return $this->memory->once($this->rule, $node, $decide);
	}
}
