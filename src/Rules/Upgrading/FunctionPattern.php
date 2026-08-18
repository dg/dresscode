<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Violation;
use PhpSyntax\Nodes\ArgumentListNode;


/**
 * The key of a map naming a global function, written the way an upgrading guide writes it: `utf8_encode($string)`
 * is a call of the function with arguments of that shape, `curl_close($handle)` one with a single argument, and the
 * name alone the function however it is called. The shape of the arguments reads as that of a member does.
 */
final readonly class FunctionPattern
{
	private function __construct(
		/** @var lowercase-string  fully qualified, without a leading backslash, in lower case as PHP reads it */
		public string $name,
		/** the shape the arguments of a call have to have; null for a key without parentheses, which takes any */
		public ?ArgumentPattern $arguments = null,
	) {
	}


	/** @throws \InvalidArgumentException  saying what is wrong with the key */
	public static function fromKey(string $key): self
	{
		if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)(?:\((.*)\))?$~Ds', trim($key), $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException('The function ' . Violation::formatCode($key) . ' is not written as `name` or `name($argument, ...)`.');
		}

		[, $name, $parentheses] = $m;
		try {
			$arguments = $parentheses === null ? null : ArgumentPattern::parse($parentheses);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('The function ' . Violation::formatCode($key) . " cannot be read: {$e->getMessage()}", previous: $e);
		}

		return new self(strtolower($name), $arguments);
	}


	/** What the arguments of a call are in the words of the key; null where the call is of another shape. */
	public function bind(ArgumentListNode $arguments): ?ArgumentBindings
	{
		return ($this->arguments ?? ArgumentPattern::any())->bind($arguments);
	}
}
