<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NameNode;


/**
 * What a template makes of one call: the expression written instead, or why there is none, and why writing it may
 * change what the code does.
 * @internal
 */
final readonly class Rewrite
{
	public function __construct(
		/** detached, null where the call is refused */
		public ?ExpressionNode $expression,
		/** a clause of the message, `, but ...` */
		public ?string $refusal = null,
		/** a clause of the message, `, which ...` */
		public ?string $risk = null,
		/** @var list<NameNode>  the classes the template names fully qualified, to be spelled the way the code reaches them */
		public array $classes = [],
	) {
	}
}
