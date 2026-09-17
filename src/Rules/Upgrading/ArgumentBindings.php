<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use PhpSyntax\Nodes\ArgumentNode;


/**
 * What the arguments of a call are in the words of the pattern they were bound to.
 */
final readonly class ArgumentBindings
{
	public function __construct(
		/** @var array<string, ArgumentNode|list<ArgumentNode>>  placeholder → its argument, those of a variadic one in a list */
		public array $arguments,
		/** @var list<ArgumentNode>  what `...` stands for, in the order of the call, the named ones among them */
		public array $rest = [],
		/** @var list<string>  the placeholders bound to an expression whose keys are not seen, being neither an array literal nor a list */
		public array $unseenKeys = [],
	) {
	}
}
