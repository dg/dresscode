<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Parameter, Types};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, ArrayItemNode, ExpressionNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, FunctionCallNode, VariableNode};
use PhpSyntax\{ParseException, Parser};
use function count, in_array, is_array, is_bool, is_float, is_int, is_string;


/**
 * The shape of the arguments a key of a map of members asks of a call, written as the arguments of a call are:
 * `$name, $label, true`, `miss: $f, ...`, `$callable, ...$args`. A placeholder stands for any expression,
 * `$this` alone for none, being what the call is made on in the expression written instead; a placeholder with
 * a type in front of it, as a parameter has one, only for an argument of that type for certain, `array $options`
 * for an array, so that `new Range(3)` is not what `Range::__construct(array $options)` asks for, `list $choices`
 * for a list, `bool $strict`, `?Acme\Clock $clock`; a literal stands for the same value however written, an array
 * of string keys with placeholders, `['mode' => $mode, ...$options]`, for an array literal that has those keys, its
 * other items under `...$name` or none, an item under a name for an argument passed by that name, and the rest of
 * the arguments has to be asked for, with `...` or `...$args`, or the call may have none.
 */
final readonly class ArgumentPattern
{
	private const BuiltinTypes = ['array', 'list', 'bool', 'true', 'false', 'int', 'float', 'string', 'iterable', 'callable', 'object', 'mixed', 'null'];


	private function __construct(
		/** @var list<ArgumentPatternItem>  the positional ones, the named ones, and last the one standing for the rest */
		public array $items,
	) {
	}


	/** @throws \InvalidArgumentException  saying which item cannot be read, as the end of a sentence about the key */
	public static function parse(string $inside): self
	{
		[$inside, $types] = self::extractTypes($inside);
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
			$type = $placeholder === null ? null : $types[$placeholder] ?? null;
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
				!$variadic && $placeholder !== null => new ArgumentPatternItem($placeholder, parameterName: $name, type: $type),
				!$variadic && $value?->hasValue() => new ArgumentPatternItem(literal: [$value->toValue()], parameterName: $name),
				!$variadic && $value instanceof ArrayNode => self::parseKeys($value, $name, $placeholders),
				default => throw new \InvalidArgumentException("'$argument->text' is no placeholder, no literal, no array of keys, and neither '...' nor '...\$name'."),
			};
		}

		return new self($items);
	}


	/**
	 * The item of an array literal the argument has to be, `['mode' => $mode, ...$options]`: string keys, each with
	 * the placeholder of its value, and last the placeholder of the other items, without which the array has none.
	 * @param  list<?string>  $placeholders  those of the pattern so far, which the array adds its own to
	 * @throws \InvalidArgumentException
	 */
	private static function parseKeys(ArrayNode $array, ?string $name, array &$placeholders): ArgumentPatternItem
	{
		$keys = [];
		$others = null;
		foreach ($array->items->getItems() as $item) {
			if (!$item instanceof ArrayItemNode) {
				throw new \InvalidArgumentException("the array '$array->text' has an empty item.");
			}

			$placeholder = $item->value instanceof VariableNode ? $item->value->plainName : null;
			$key = $item->key?->hasValue() ? $item->key->toValue() : null;
			if ($others !== null) {
				throw new \InvalidArgumentException("'$item->text' stands behind the placeholder of the other items of the array.");
			} elseif ($placeholder === 'this') {
				throw new \InvalidArgumentException('$this is no placeholder; in the expression written instead it stands for what the call is made on.');
			} elseif ($placeholder !== null && in_array($placeholder, $placeholders, true)) {
				throw new \InvalidArgumentException("the placeholder \$$placeholder stands for two arguments.");
			} elseif ($placeholder !== null && $item->ellipsis !== null && $item->key === null) {
				$others = $placeholder;
			} elseif ($placeholder !== null && is_string($key) && $item->ellipsis === null && $item->ampersand === null) {
				$keys[$key] = $placeholder;
			} else {
				throw new \InvalidArgumentException("'$item->text' in an array is neither a string key with the placeholder of its value nor '...\$name'.");
			}

			$placeholders[] = $placeholder;
		}

		if ($keys === []) {
			throw new \InvalidArgumentException("the array '$array->text' names no key.");
		}

		return new ArgumentPatternItem(parameterName: $name, keys: $keys, otherItems: $others);
	}


	/**
	 * The arguments without the types written in front of placeholders, and those types by their placeholders.
	 * @return array{string, array<string, string>}
	 * @throws \InvalidArgumentException
	 */
	private static function extractTypes(string $inside): array
	{
		$tokens = \PhpToken::tokenize("<?php $inside");
		array_shift($tokens);
		$code = '';
		$types = [];
		$pending = []; // what may be the type of a placeholder: names, ?, | and the spaces among them
		$depth = 0;
		foreach ($tokens as $index => $token) {
			$typeLike = $depth === 0 && ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_LIST, T_CALLABLE, T_WHITESPACE]) || $token->text === '?' || $token->text === '|');
			$next = array_find(array_slice($tokens, $index + 1), fn(\PhpToken $token) => !$token->is(T_WHITESPACE));
			if ($typeLike && !($token->is(T_STRING) && ($next?->text === ':' || $next?->is(T_DOUBLE_COLON) || $next?->text === '('))) {
				$pending[] = $token;
				continue;
			}

			$type = trim(implode('', array_map(fn(\PhpToken $token) => $token->text, $pending)));
			if ($token->is(T_VARIABLE) && $type !== '') {
				$types[substr($token->text, 1)] = self::normalizeType($type, $token->text);
				$code .= $pending[0]->is(T_WHITESPACE) ? $pending[0]->text : '';
			} else {
				$code .= implode('', array_map(fn(\PhpToken $token) => $token->text, $pending));
			}

			$pending = [];
			$depth += match ($token->text) {
				'(', '[', '{' => 1,
				')', ']', '}' => -1,
				default => 0,
			};
			$code .= $token->text;
		}

		return [$code . implode('', array_map(fn(\PhpToken $token) => $token->text, $pending)), $types];
	}


	/** The type as the pattern keeps it, its classes without a leading backslash. */
	private static function normalizeType(string $type, string $placeholder): string
	{
		$members = explode('|', (string) preg_replace('~\s+~', '', $type));
		foreach ($members as &$member) {
			$nullable = str_starts_with($member, '?');
			$member = ltrim($member, '?\\');
			if (!preg_match('~^[a-z_]\w*(\\\\[a-z_]\w*)*$~iD', $member) || ($nullable && count($members) > 1)) {
				throw new \InvalidArgumentException("'$type $placeholder' does not write a type as PHP writes one.");
			}

			$member = in_array(strtolower($member), self::BuiltinTypes, true) ? strtolower($member) : $member;
			$member = ($nullable ? '?' : '') . $member;
		}

		return implode('|', $members);
	}


	/**
	 * The arguments of the call in the words of the pattern, or null where the call is not of its shape: an item has
	 * no argument, a literal another value, an argument of a placeholder with a type is not of that type for certain,
	 * an argument is left that nothing asked for. A positional item takes the argument passed by the name of its
	 * parameter too, which needs the parameters of the method; without them a call passing it by name is of no shape.
	 * A call that leaves arguments open with `?` or `...` is none either. The type of an argument other than a literal
	 * is known only with the types.
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 */
	public function bind(ArgumentListNode $arguments, ?array $parameters, ?Types $types = null): ?ArgumentBindings
	{
		if ($arguments->isPartialApplication()) {
			return null;
		}

		$bound = $taken = $unseen = $takenApart = [];
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
				|| ($item->type !== null && !self::isOfType($argument, $item->type, $types))
			) {
				return null;
			}

			$taken[] = $argument;
			if ($item->keys !== null) {
				$keys = self::bindKeys($item, $argument);
				if ($keys === null) {
					return null;
				}

				[$values, $takenApart[spl_object_id($argument)]] = $keys;
				$bound += $values;

			} elseif ($item->placeholder !== null) {
				$bound[$item->placeholder] = $argument;
				if (!$argument->value instanceof ArrayNode && !$argument->value->hasValue() && !$types?->isOfType($argument->value, 'list')) {
					$unseen[] = $item->placeholder;
				}
			}
		}

		$rest = [];
		foreach ($arguments->items as $argument) {
			if ($argument instanceof ArgumentNode && !in_array($argument, $taken, true)) {
				$rest[] = $argument;
			}
		}

		if ($tail === null) {
			return $rest === [] ? new ArgumentBindings($bound, unseenKeys: $unseen, takenApart: $takenApart) : null;
		} elseif ($tail->placeholder === null) {
			return new ArgumentBindings($bound, $rest, $unseen, $takenApart);
		} elseif (array_any($rest, fn(ArgumentNode $argument) => $argument->name !== null)) {
			return null; // a name has no place among the arguments a variadic placeholder writes elsewhere
		}

		return new ArgumentBindings($bound + [$tail->placeholder => $rest], unseenKeys: $unseen, takenApart: $takenApart);
	}


	/**
	 * The values of the keys of the array literal the argument is, each as an argument of its own, and its other items,
	 * with the placeholders and the values of all of them in the order of the array. Null where the argument is no
	 * array literal, lacks a key, or has an item the pattern cannot tell: unpacked, taken by reference, under a key
	 * that is no literal, or one the pattern has no placeholder for.
	 * @return ?array{array<string, ArgumentNode|list<ArrayItemNode>>, array{ArrayNode, ?string, list<array{string, ExpressionNode}>}}
	 */
	private static function bindKeys(ArgumentPatternItem $item, ArgumentNode $argument): ?array
	{
		$array = $argument->value;
		if ($argument->ellipsis !== null || !$array instanceof ArrayNode || $array->hasComment()) {
			return null;
		}

		$bound = $others = $order = [];
		foreach ($array->items->getItems() as $arrayItem) {
			if (
				!$arrayItem instanceof ArrayItemNode
				|| $arrayItem->ellipsis !== null
				|| $arrayItem->ampersand !== null
				|| !$arrayItem->value instanceof ExpressionNode
				|| ($arrayItem->key !== null && !$arrayItem->key->hasValue())
			) {
				return null;
			}

			$key = $arrayItem->key?->toValue();
			$placeholder = is_string($key) ? $item->keys[$key] ?? null : null;
			if ($placeholder !== null && !isset($bound[$placeholder])) {
				$call = (new Parser)->parseExpression('f(0)');
				assert($call instanceof FunctionCallNode && $call->arguments->items->getItems()[0] instanceof ArgumentNode);
				$bound[$placeholder] = $call->arguments->items->getItems()[0];
				$bound[$placeholder]->value->replaceWithExpression($arrayItem->value->withoutEdgeTrivia());
				$order[] = [$placeholder, $arrayItem->value];
			} elseif ($item->otherItems !== null) {
				$others[] = $arrayItem->withoutEdgeTrivia();
				$order[] = [$item->otherItems, $arrayItem->value];
			} else {
				return null;
			}
		}

		if (count($bound) !== count((array) $item->keys)) {
			return null;
		}

		return [$item->otherItems === null ? $bound : $bound + [$item->otherItems => $others], [$array, $item->otherItems, $order]];
	}


	/** Whether the argument is of the type for certain: a literal by its value, anything else by the types. */
	private static function isOfType(ArgumentNode $argument, string $type, ?Types $types): bool
	{
		if ($argument->ellipsis !== null) {
			return false;
		} elseif (!$argument->value->hasValue()) {
			return $types?->isOfType($argument->value, $type) ?? false;
		}

		$value = $argument->value->toValue();
		$members = explode('|', ltrim($type, '?'));
		$members = str_starts_with($type, '?') ? [...$members, 'null'] : $members;
		return array_any($members, fn(string $member) => match ($member) {
			'mixed' => true,
			'null' => $value === null,
			'bool' => is_bool($value),
			'true' => $value === true,
			'false' => $value === false,
			'int' => is_int($value),
			'float' => is_float($value) || is_int($value),
			'string' => is_string($value),
			'array', 'iterable' => is_array($value),
			'list' => is_array($value) && array_is_list($value),
			default => false, // a class, which no literal is
		});
	}


	/** Whether the pattern is the rest alone, which any arguments are of. */
	public function takesAny(): bool
	{
		return count($this->items) === 1 && $this->items[0]->variadic;
	}


	/**
	 * Which of two patterns of one method is asked first, as a comparison function: the one with more literals, a key
	 * of an array counting as one, then
	 * the one with more types, `list` counting as narrower than any other, then the one that takes no rest, then the
	 * longer one.
	 */
	public function compareSpecificity(self $other): int
	{
		$measure = fn(self $pattern) => [
			array_sum(array_map(fn(ArgumentPatternItem $item) => $item->literal !== null ? 1 : count($item->keys ?? []), $pattern->items)),
			array_sum(array_map(fn(ArgumentPatternItem $item) => match ($item->type) {
				null => 0,
				'list' => 2,
				default => 1,
			}, $pattern->items)),
			(int) !array_any($pattern->items, fn(ArgumentPatternItem $item) => $item->variadic),
			count($pattern->items),
		];
		return $measure($other) <=> $measure($this);
	}
}
