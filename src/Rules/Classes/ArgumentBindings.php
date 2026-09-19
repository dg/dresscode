<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use PhpSyntax\Nodes\ArgumentNode;


/**
 * What the arguments of a call are in the words of the pattern they were bound to.
 * @internal
 */
final readonly class ArgumentBindings
{
	public function __construct(
		/** @var array<string, ArgumentNode|list<ArgumentNode>>  placeholder → its argument, those of a variadic one in a list */
		public array $arguments,
		/** @var list<ArgumentNode>  what `...` stands for, in the order of the call, the named ones among them */
		public array $rest = [],
	) {
	}
}
