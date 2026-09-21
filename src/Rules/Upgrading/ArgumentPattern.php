<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Parameter, Types};
use DressCode\{Tristate, Violation};
use PhpSyntax\{Builder, ParseException};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, FunctionCallNode, VariableNode};
use function count, is_array, is_bool, is_float, is_int, is_string;


/**
 * The shape of the arguments a key of a map of members asks of a call, written as the arguments of a call are:
 * `$name, $label, true`, `miss: $f, ...`, `$callable, ...$args`. A placeholder stands for any expression,
 * `$this` alone for none, being what the call is made on in the expression written instead; a placeholder with
 * a type in front of it, as a parameter has one, only for an argument of that type for certain, `array $options`
 * for an array, so that `new Range(3)` is not what `Range::__construct(array $options)` asks for, `list $choices`
 * for a list, `bool $strict`, `?Acme\Clock $clock`; a literal stands for the same value however written, an item
 * under a name for an argument passed by that name, and the rest of the arguments has to be asked for, with `...`
 * or `...$args`, or the call may have none. A type in front of the rest, `int|string ...$kinds`, is that of each of
 * its arguments, as of a variadic parameter, and of each value an unpacked one holds, so that `is(...self::Operators)`
 * is what `Token::is(int|string ...$kinds)` asks for and `is([$a, $b])`, the code it may be written as, is not.
 */
final readonly class ArgumentPattern
{
	private const BuiltinTypes = ['array', 'list', 'bool', 'true', 'false', 'int', 'float', 'string', 'iterable', 'callable', 'object', 'mixed', 'null'];


	private function __construct(
		/** @var list<ArgumentPatternItem>  the positional ones, the named ones, and last the one standing for the rest */
		public array $items,
	) {
	}


	/** The pattern of any arguments, `...`. */
	public static function any(): self
	{
		return new self([new ArgumentPatternItem(rest: true)]);
	}


	/** @throws \InvalidArgumentException  saying which item cannot be read, as the end of a sentence about the key */
	public static function parse(string $arguments): self
	{
		[$arguments, $types] = self::extractTypes($arguments);
		try {
			$call = (new Builder)->fragment(FunctionCallNode::class, "f($arguments)");
		} catch (ParseException $e) {
			throw new \InvalidArgumentException("the arguments do not read as those of a call: {$e->getMessage()}", previous: $e);
		}

		$items = $placeholders = [];
		$named = false;
		foreach ($call->arguments->items as $argument) {
			if ($items !== [] && $items[count($items) - 1]->rest) {
				throw new \InvalidArgumentException(Violation::formatCode($argument->text) . ' stands behind the item that takes the rest of the arguments.');
			}

			if ($argument instanceof VariadicPlaceholderNode) {
				$items[] = new ArgumentPatternItem(rest: true);
				continue;
			}

			$value = $argument instanceof ArgumentNode && $argument->ampersand === null ? $argument->value : null;
			$name = $argument->name?->text;
			$placeholder = $value instanceof VariableNode ? $value->plainName : null;
			$variadic = $argument instanceof ArgumentNode && $argument->ellipsis !== null;
			$type = $placeholder === null ? null : $types[$placeholder] ?? null;
			if ($placeholder === 'this') {
				throw new \InvalidArgumentException('`$this` is no placeholder; in the expression written instead it stands for what the call is made on.');
			} elseif ($placeholder !== null && in_array($placeholder, $placeholders, true)) {
				throw new \InvalidArgumentException("the placeholder `\$$placeholder` stands for two arguments.");
			} elseif ($name === null && $named && !$variadic) {
				throw new \InvalidArgumentException('the positional ' . Violation::formatCode($argument->text) . ' stands behind a named item.');
			}

			$named = $named || $name !== null;
			$placeholders[] = $placeholder;
			$items[] = match (true) {
				$variadic && $placeholder !== null && $name === null => new ArgumentPatternItem($placeholder, rest: true, type: $type),
				!$variadic && $placeholder !== null => new ArgumentPatternItem($placeholder, parameterName: $name, type: $type),
				!$variadic && $value?->hasValue() => new ArgumentPatternItem(literal: [$value->toValue()], parameterName: $name),
				default => throw new \InvalidArgumentException(Violation::formatCode($argument->text) . ' is no placeholder, no literal, and neither `...` nor `...$name`.'),
			};
		}

		return new self($items);
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

			// the type of a placeholder stands in front of it, and of the rest in front of its `...`
			$type = trim(implode('', array_map(fn(\PhpToken $token) => $token->text, $pending)));
			$variable = $token->is(T_ELLIPSIS) ? $next : $token;
			if ($token->is(T_ELLIPSIS) && $type !== '' && !$next?->is(T_VARIABLE)) {
				throw new \InvalidArgumentException("the type `$type` stands in front of `...`, which has no placeholder to take it, `$type ...\$args`.");
			} elseif ($variable?->is(T_VARIABLE) && $type !== '') {
				$types[substr($variable->text, 1)] = self::normalizeType($type, ($token->is(T_ELLIPSIS) ? '...' : '') . $variable->text);
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
				throw new \InvalidArgumentException(Violation::formatCode("$type $placeholder") . ' does not write a type as PHP writes one.');
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
	 * @param  bool  $acceptUncertainTypes  a type the types cannot settle counts as held, for whoever asks whether a call may be of the shape
	 */
	public function bind(
		ArgumentListNode $arguments,
		?array $parameters,
		?Types $types = null,
		bool $acceptUncertainTypes = false,
	): ?ArgumentBindings
	{
		if ($arguments->isPartialApplication()) {
			return null;
		}

		$bound = $taken = $unseen = [];
		$tail = null;
		foreach ($this->items as $index => $item) {
			if ($item->rest) {
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
				|| ($item->type !== null && !self::isOfType($argument, $item->type, $types, $acceptUncertainTypes))
			) {
				return null;
			}

			$taken[] = $argument;
			if ($item->placeholder !== null) {
				$bound[$item->placeholder] = $argument;
				if (
					!$argument->value instanceof ArrayNode
					&& !$argument->value->hasValue()
					&& $types?->isOfType($argument->value, 'list') !== Tristate::Yes
				) {
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
			return $rest === [] ? new ArgumentBindings($bound, unseenKeys: $unseen) : null;
		} elseif ($tail->placeholder === null) {
			return new ArgumentBindings($bound, $rest, $unseen);
		} elseif (array_any($rest, fn(ArgumentNode $argument) => $argument->name !== null)) {
			return null; // a name has no place among the arguments a variadic placeholder writes elsewhere
		} elseif (
			$tail->type !== null
			&& !array_all($rest, fn(ArgumentNode $argument) => self::isOfType($argument, (string) $tail->type, $types, $acceptUncertainTypes))
		) {
			return null;
		}

		return new ArgumentBindings($bound + [$tail->placeholder => $rest], unseenKeys: $unseen);
	}


	/**
	 * Whether the argument is of the type for certain: a literal by its value, anything else by the types, and one that
	 * unpacks an iterable by every value it holds. Where the types cannot settle it, `$acceptUncertainTypes` takes an argument
	 * that may be of the type for one, an array literal being an array.
	 */
	private static function isOfType(ArgumentNode $argument, string $type, ?Types $types, bool $acceptUncertainTypes = false): bool
	{
		$value = $argument->value;
		$unpacked = $argument->ellipsis !== null;
		if ($value->hasValue()) {
			$values = $value->toValue();
			return $unpacked
				? is_array($values) && array_is_list($values) && array_all($values, fn(mixed $item) => self::holdsType($item, $type))
				: self::holdsType($values, $type);
		}

		$held = $types?->isOfType($value, $unpacked ? "iterable<int, $type>" : $type); // a string key unpacks as a name
		return match (true) {
			$held === Tristate::Yes => true,
			$held === Tristate::No || !$acceptUncertainTypes => false,
			!$unpacked && $value instanceof ArrayNode => self::holdsType([], $type),
			default => true,
		};
	}


	/** Whether the value of a literal is of the type. */
	private static function holdsType(mixed $value, string $type): bool
	{
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


	/** Whether the pattern ends with `...`, which takes the arguments it names in no other way. */
	public function takesRest(): bool
	{
		$last = $this->items[count($this->items) - 1] ?? null;
		return $last !== null && $last->rest && $last->placeholder === null;
	}


	/** Whether the pattern is the rest alone, which any arguments are of. */
	public function takesAnyArguments(): bool
	{
		return count($this->items) === 1 && $this->items[0]->rest;
	}


	/**
	 * Which of two patterns of one method is asked first, as a comparison function: the one with more literals, then
	 * the one with more types, `list` counting as narrower than any other, then the one that takes no rest, then the
	 * longer one.
	 */
	public function compareSpecificity(self $other): int
	{
		$measure = fn(self $pattern) => [
			count(array_filter($pattern->items, fn(ArgumentPatternItem $item) => $item->literal !== null)),
			array_sum(array_map(fn(ArgumentPatternItem $item) => match ($item->type) {
				null => 0,
				'list' => 2,
				default => 1,
			}, $pattern->items)),
			(int) !array_any($pattern->items, fn(ArgumentPatternItem $item) => $item->rest),
			count($pattern->items),
		];
		return $measure($other) <=> $measure($this);
	}
}
