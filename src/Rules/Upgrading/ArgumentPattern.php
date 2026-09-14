<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\Parameter;
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, VariableNode};
use PhpSyntax\{ParseException, Parser};
use function count, in_array;


/**
 * The shape of the arguments a key of a map of members asks of a call, written as the arguments of a call are:
 * `$name, $label, true`, `miss: $f, ...`, `$callable, ...$args`. A placeholder stands for any expression,
 * `$this` alone for none, being what the call is made on in the expression written instead; a literal stands for
 * the same value however written, an item under a name for an argument passed by that name, and the rest of the
 * arguments has to be asked for, with `...` or `...$args`, or the call may have none.
 */
final readonly class ArgumentPattern
{
	private function __construct(
		/** @var list<ArgumentPatternItem>  the positional ones, the named ones, and last the one standing for the rest */
		public array $items,
	) {
	}


	/** @throws \InvalidArgumentException  saying which item cannot be read, as the end of a sentence about the key */
	public static function parse(string $inside): self
	{
		try {
			$call = (new Parser)->parseExpression("f($inside)");
		} catch (ParseException $e) {
			throw new \InvalidArgumentException("the arguments do not read as those of a call: {$e->getMessage()}", previous: $e);
		}

		assert($call instanceof FunctionCallNode);
		$items = $placeholders = [];
		$named = false;
		foreach ($call->arguments->items as $argument) {
			if ($items !== [] && $items[count($items) - 1]->variadic) {
				throw new \InvalidArgumentException("'$argument->text' stands behind the item that takes the rest of the arguments.");
			}

			if ($argument instanceof VariadicPlaceholderNode) {
				$items[] = new ArgumentPatternItem(variadic: true);
				continue;
			}

			$value = $argument instanceof ArgumentNode && $argument->ampersand === null ? $argument->value : null;
			$name = $argument->name?->text;
			$placeholder = $value instanceof VariableNode ? $value->plainName : null;
			$variadic = $argument instanceof ArgumentNode && $argument->ellipsis !== null;
			if ($placeholder === 'this') {
				throw new \InvalidArgumentException('$this is no placeholder; in the expression written instead it stands for what the call is made on.');
			} elseif ($placeholder !== null && in_array($placeholder, $placeholders, true)) {
				throw new \InvalidArgumentException("the placeholder \$$placeholder stands for two arguments.");
			} elseif ($name === null && $named && !$variadic) {
				throw new \InvalidArgumentException("the positional '$argument->text' stands behind a named item.");
			}

			$named = $named || $name !== null;
			$placeholders[] = $placeholder;
			$items[] = match (true) {
				$variadic && $placeholder !== null && $name === null => new ArgumentPatternItem($placeholder, variadic: true),
				!$variadic && $placeholder !== null => new ArgumentPatternItem($placeholder, parameterName: $name),
				!$variadic && $value?->hasValue() => new ArgumentPatternItem(literal: [$value->toValue()], parameterName: $name),
				default => throw new \InvalidArgumentException("'$argument->text' is no placeholder, no literal, and neither '...' nor '...\$name'."),
			};
		}

		return new self($items);
	}


	/**
	 * The arguments of the call in the words of the pattern, or null where the call is not of its shape: an item has
	 * no argument, a literal another value, an argument is left that nothing asked for. A positional item takes the
	 * argument passed by the name of its parameter too, which needs the parameters of the method; without them a call
	 * passing it by name is of no shape. A call that leaves arguments open with `?` or `...` is none either.
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 */
	public function bind(ArgumentListNode $arguments, ?array $parameters): ?ArgumentBindings
	{
		if ($arguments->isPartialApplication()) {
			return null;
		}

		$bound = $taken = [];
		$tail = null;
		foreach ($this->items as $index => $item) {
			if ($item->variadic) {
				$tail = $item;
				break;
			}

			// the positional items stand first, so the index of one is its position
			$argument = $item->parameterName === null
				? $arguments->findArgument($parameters[$index]->name ?? null, $index)
				: $arguments->findArgument($item->parameterName, null);
			if (
				$argument === null
				|| ($item->literal !== null && !($argument->value->hasValue() && $argument->value->toValue() === $item->literal[0]))
			) {
				return null;
			}

			$taken[] = $argument;
			if ($item->placeholder !== null) {
				$bound[$item->placeholder] = $argument;
			}
		}

		$rest = [];
		foreach ($arguments->items as $argument) {
			if ($argument instanceof ArgumentNode && !in_array($argument, $taken, true)) {
				$rest[] = $argument;
			}
		}

		if ($tail === null) {
			return $rest === [] ? new ArgumentBindings($bound) : null;
		} elseif ($tail->placeholder === null) {
			return new ArgumentBindings($bound, $rest);
		} elseif (array_any($rest, fn(ArgumentNode $argument) => $argument->name !== null)) {
			return null; // a name has no place among the arguments a variadic placeholder writes elsewhere
		}

		return new ArgumentBindings($bound + [$tail->placeholder => $rest]);
	}


	/** Whether the pattern is the rest alone, which any arguments are of. */
	public function takesAny(): bool
	{
		return count($this->items) === 1 && $this->items[0]->variadic;
	}


	/**
	 * Which of two patterns of one method is asked first, as a comparison function: the one with more literals, then
	 * the one that takes no rest, then the longer one.
	 */
	public function compareSpecificity(self $other): int
	{
		$measure = fn(self $pattern) => [
			count(array_filter($pattern->items, fn(ArgumentPatternItem $item) => $item->literal !== null)),
			(int) !array_any($pattern->items, fn(ArgumentPatternItem $item) => $item->variadic),
			count($pattern->items),
		];
		return $measure($other) <=> $measure($this);
	}
}
