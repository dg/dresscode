<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, ExpressionNode};
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * What the arguments of a call are in the words of the pattern they were bound to.
 */
final readonly class ArgumentBindings
{
	public function __construct(
		/**
		 * placeholder → its argument, those of a variadic one in a list; the value of a key of an array literal as an
		 * argument of its own, and the other items of it in a list
		 * @var array<string, ArgumentNode|list<ArgumentNode>|list<ArrayItemNode>>
		 */
		public array $arguments,
		/** @var list<ArgumentNode>  what `...` stands for, in the order of the call, the named ones among them */
		public array $rest = [],
		/** @var list<string>  the placeholders bound to an expression whose keys are not seen, being neither an array literal nor a list */
		public array $unseenKeys = [],
		/**
		 * argument taken apart, by its object id → its array literal, the placeholder of its other items, and the
		 * placeholder and the value of each of its items in their order
		 * @var array<int, array{ArrayNode, ?string, list<array{string, ExpressionNode}>}>
		 */
		public array $takenApart = [],
	) {
	}
}
