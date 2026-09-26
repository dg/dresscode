<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use PhpSyntax\{Node, ParseException, Parser};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, ArrayItemNode, ExpressionNode, MatchArmNode, NameNode, SeparatedNodeList, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, ArrayNode, ArrowFunctionNode, BinaryOpNode, ClassConstantFetchNode, ClosureNode, FunctionCallNode, MethodCallNode, NewNode, ParenthesizedNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode, TernaryNode, VariableNode};
use function count, in_array, is_string;


/**
 * What replaced-calls writes instead of a call, as one expression of PHP with the placeholders of the key in it:
 * `getOption($key) ?? $default`, `\Closure::fromCallable($callable)`, `$callable(...$args)`. A call of a bare name,
 * `hasMode('debug')`, is a call of that member on what the replaced one was called on, in the same way, `->`, `?->`
 * or `::`; a qualified name is a function or a class of its own. `$this` is what the call was made on, written
 * anywhere an expression stands, `\Acme\Events::dispatch($this->listeners, ...$args)`; only a call made on
 * an object has one. `...` writes the arguments the key left unnamed, `...$args` those a variadic placeholder
 * stands for or the values of an array one does, an array literal written out as the arguments PHP makes of it,
 * `new Range(...['min' => $m])` as `new Range(min: $m)`, and `...$options` in an array the other items of an array
 * the key took apart, `['follow' => $f, ...$options]`. A call made with `?->` is written only where the
 * expression calls a bare name and writes no `$this`, the operator carrying over to that call; anything else is
 * refused, for it would call on null what the call did not.
 *
 * Where an argument ends up decides what the rewrite is: one written twice would be evaluated twice, which no consent
 * mends, so the call is refused unless reading it again is free; one that moved where PHP evaluates only sometimes
 * (the right of `??`, `&&`, `||`, a branch of `?:` or of `match`, the body of a closure, past `?->`) or that is
 * written nowhere is no longer evaluated as it was, which is risky where evaluating it may do something, and so are two such
 * arguments written in another order than the call has them. What the call was made on is judged the same way.
 * @internal
 */
final class CallTemplate
{
	private const Receiver = "\0receiver";


	private function __construct(
		public readonly string $code,
		private readonly ParenthesizedNode $holder,
		/** @var array<string, int>  placeholder, or the receiver => how many times the template writes it, in the order it first does */
		private readonly array $uses,
		/** @var array<string, true>  placeholders, or the receiver, written where PHP evaluates only sometimes */
		private readonly array $lazy,
		private readonly bool $writesRest,
		/** the receiver is written as $this, which takes an expression, not a class */
		private readonly bool $writesThis,
		/** @var array<string, true>  placeholders of one argument the template unpacks, `...$options` */
		private readonly array $unpacked = [],
	) {
	}


	/** @throws \InvalidArgumentException  saying why the code cannot stand for a call of the key */
	public static function fromCode(string $code, MemberPattern $key): self
	{
		try {
			$holder = ParenthesizedNode::of((new Parser)->parseExpression($code));
		} catch (ParseException $e) {
			throw new \InvalidArgumentException("The code '$code' written instead of $key->class::$key->name does not read as an expression: {$e->getMessage()}", previous: $e);
		}

		$items = $key->arguments->items ?? [];
		$uses = $lazy = $unpackedItems = [];
		$writesThis = false;
		foreach ($holder->find(VariableNode::class) as $variable) {
			if ($variable->isThis()) {
				$writesThis = true;
				$uses[self::Receiver] = ($uses[self::Receiver] ?? 0) + 1;
				$lazy += self::isLazy($variable) ? [self::Receiver => true] : [];
				continue;
			}

			$name = (string) $variable->plainName;
			$item = array_find($items, fn(ArgumentPatternItem $item) => $item->placeholder === $name
				|| in_array($name, $item->keys ?? [], true)
				|| $item->otherItems === $name);
			$unpacked = ($variable->parent instanceof ArgumentNode || $variable->parent instanceof ArrayItemNode) && $variable->parent->ellipsis !== null;
			$others = $item !== null && $item->otherItems === $name;
			if ($item === null) {
				throw new \InvalidArgumentException("The code '$code' uses \$$name, which the key $key->class::$key->name does not name.");
			} elseif ($item->variadic && !$unpacked) {
				throw new \InvalidArgumentException("The code '$code' must write \$$name as the arguments it stands for, '...\$$name'.");
			} elseif ($others && !($unpacked && $variable->parent instanceof ArrayItemNode)) {
				throw new \InvalidArgumentException("The code '$code' must write \$$name as the items it stands for, '...\$$name' in an array.");
			}

			$uses[$name] = ($uses[$name] ?? 0) + 1;
			$lazy += self::isLazy($variable) ? [$name => true] : [];
			$unpackedItems += $unpacked && !$item->variadic && !$others ? [$name => true] : [];
		}

		foreach ($holder->find(FunctionCallNode::class, self::isBareCall(...)) as $call) {
			$uses[self::Receiver] = ($uses[self::Receiver] ?? 0) + 1;
			$lazy += self::isLazy($call) ? [self::Receiver => true] : [];
		}

		// in the order the expression evaluates them, which is the order it writes them in
		$order = [];
		foreach ($holder->find(ExpressionNode::class) as $node) {
			$name = match (true) {
				$node instanceof VariableNode => $node->isThis() ? self::Receiver : (string) $node->plainName,
				$node instanceof FunctionCallNode && self::isBareCall($node) => self::Receiver,
				default => null,
			};
			$order += $name === null ? [] : [$name => 0];
		}

		$uses = array_replace($order, $uses);
		$rests = $holder->find(VariadicPlaceholderNode::class);
		$takesRest = $key->arguments === null || array_any($items, fn(ArgumentPatternItem $item) => $item->variadic && $item->placeholder === null);
		if ($rests !== [] && !$takesRest) {
			throw new \InvalidArgumentException("The code '$code' writes ..., which the key $key->class::$key->name does not take.");
		}

		return new self($code, $holder, $uses, $lazy, $rests !== [], $writesThis, $unpackedItems);
	}


	/**
	 * The expression written instead of the call, or why there is none, and why writing it may change what the code does.
	 * @param  NameNode|ExpressionNode|null  $receiver  what the call is made on, the object or the class; null for an instantiation
	 * @param  bool  $static  the call is written with ::
	 */
	public function instantiate(
		ArgumentBindings $bindings,
		ArgumentListNode $arguments,
		NameNode|ExpressionNode|null $receiver,
		bool $static = false,
		bool $nullsafe = false,
	): Rewrite
	{
		if (($this->uses[self::Receiver] ?? 0) > 0 && $receiver === null) {
			return new Rewrite(null, ', but an instantiation has no object to call it on');
		} elseif ($this->writesThis && !$receiver instanceof ExpressionNode) {
			return new Rewrite(null, ', but the replacement needs an object and the call is static');
		} elseif ($arguments->hasComment()) {
			return new Rewrite(null, ', but a comment stands among its arguments');
		} elseif ($nullsafe && (($this->uses[self::Receiver] ?? 0) === 0 || $this->writesThis)) {
			return new Rewrite(null, ', but the replacement cannot skip a null as ?-> does');
		}

		// what is judged, in the order the call evaluates it: what the call was made on, every expression a placeholder
		// stands for and the unnamed arguments the template does not write
		$judged = $receiver instanceof ExpressionNode ? [[self::Receiver, $receiver]] : []; // a class written by its name is nothing to evaluate
		$names = [];
		foreach ($bindings->arguments as $placeholder => $bound) {
			foreach ($bound instanceof ArgumentNode ? [$bound] : $bound as $argument) {
				$names[spl_object_id($argument)] = $placeholder;
			}
		}

		foreach ($arguments->items as $argument) {
			if ($argument instanceof ArgumentNode && isset($bindings->takenApart[spl_object_id($argument)])) {
				array_push($judged, ...$bindings->takenApart[spl_object_id($argument)][2]); // an array literal is its items
				continue;
			}

			$name = $names[spl_object_id($argument)] ?? null;
			if ($argument instanceof ArgumentNode && ($name !== null || !$this->writesRest)) {
				$judged[] = [$name, $argument->value];
			}
		}

		$risk = null;
		$evaluated = [];
		foreach ($judged as [$name, $expression]) {
			if ($expression->isRepeatableRead() || $expression->hasValue()) {
				continue; // evaluating it does nothing, however many times
			}

			$evaluated[] = $name;

			$uses = $name === null ? 0 : $this->uses[$name] ?? 0;
			$what = $name === self::Receiver ? 'what it is reached through' : $expression->text;
			if ($uses > 1) {
				return new Rewrite(null, ", but $what would be evaluated $uses times");
			} elseif ($uses === 0) {
				$risk ??= ", which no longer evaluates $what";
			} elseif (isset($this->lazy[$name])) {
				$risk ??= ", which evaluates $what only sometimes";
			}
		}

		// what does something when evaluated, in the order of the call and in the order the template writes it
		$written = array_values(array_intersect(array_keys($this->uses), $evaluated));
		if ($written !== array_values(array_intersect($evaluated, $written))) {
			$risk ??= ', which evaluates its arguments in another order';
		}

		foreach ($bindings->unseenKeys as $placeholder) {
			$bound = $bindings->arguments[$placeholder];
			if (isset($this->unpacked[$placeholder]) && $bound instanceof ArgumentNode) {
				$risk ??= ", which unpacks {$bound->value->text}, whose keys may not be the names of parameters";
			}
		}

		[$expression, $classes] = $this->build($bindings, $receiver, $static, $nullsafe);
		return new Rewrite($expression, risk: $risk, classes: $classes);
	}


	/** @return array{ExpressionNode, list<NameNode>} */
	private function build(
		ArgumentBindings $bindings,
		NameNode|ExpressionNode|null $receiver,
		bool $static,
		bool $nullsafe,
	): array
	{
		// what the template wrote is found before anything of the call is written into it
		$holder = $this->holder->withoutEdgeTrivia();
		foreach ($holder->find(ArrayNode::class) as $array) {
			self::renameKeys($array, $bindings);
		}

		$variables = $holder->find(VariableNode::class);
		$rests = $holder->find(VariadicPlaceholderNode::class);
		$calls = $holder->find(FunctionCallNode::class, self::isBareCall(...));
		$classes = $holder->find(NameNode::class, fn(NameNode $name) => $name->isFullyQualified()
			&& ($name->parent instanceof StaticMethodCallNode
				|| $name->parent instanceof NewNode
				|| $name->parent instanceof ClassConstantFetchNode
				|| $name->parent instanceof StaticPropertyFetchNode)
			&& $name->parent->class === $name);
		$lists = [];
		foreach ($variables as $variable) {
			if ($variable->isThis()) {
				assert($receiver instanceof ExpressionNode);
				$variable->replaceWithExpression($receiver->withoutEdgeTrivia());
				continue;
			}

			$bound = $bindings->arguments[(string) $variable->plainName];
			$spread = $bound instanceof ArgumentNode && $variable->parent instanceof ArgumentNode && $variable->parent->ellipsis !== null
				? self::spreadArray($bound->value)
				: null;
			if ($spread !== null) {
				$lists[] = self::writeArguments($variable->parent, $spread);
			} elseif ($bound instanceof ArgumentNode) {
				$variable->replaceWithExpression($bound->value->withoutEdgeTrivia());
			} else {
				$lists[] = self::writeArguments($variable->parent, $bound);
			}
		}

		foreach ($rests as $rest) {
			$lists[] = self::writeArguments($rest, $bindings->rest);
		}

		foreach ($lists as $list) {
			self::movePositionalFirst($list);
		}

		// the innermost first, so that a call in the arguments of another is there when the outer one is written
		foreach (array_reverse($calls) as $call) {
			assert($call->name instanceof NameNode && $receiver !== null);
			$on = $receiver->withoutEdgeTrivia();
			// the arguments move, so the classes found above stay in the expression; what follows them stays with the call
			$arguments = $call->arguments;
			$call->arguments = ArgumentListNode::of();
			$call->arguments->setEdgeTrivia(null, $arguments->getLastToken()->trailingTrivia ?? []);
			$call->replaceWithExpression($static || !$on instanceof ExpressionNode
				? StaticMethodCallNode::of($on, $call->name->text, $arguments)
				: MethodCallNode::of($on, $call->name->text, $arguments, $nullsafe));
		}

		return [$holder->expression, $classes]; // the holder has no file, so a write takes the expression out of it
	}


	/**
	 * An array of the template that writes nothing but the keys of an array of the call under other names, and its
	 * other items, becomes that array with those keys renamed, so that its layout, its comments and the order of its
	 * items stay as the call has them.
	 */
	private static function renameKeys(ArrayNode $template, ArgumentBindings $bindings): void
	{
		$keys = [];
		$others = null;
		foreach ($template->items->getItems() as $item) {
			$placeholder = $item instanceof ArrayItemNode && $item->value instanceof VariableNode ? (string) $item->value->plainName : null;
			if ($placeholder === null || $item->ampersand !== null) {
				return;
			} elseif ($item->ellipsis !== null && $item->key === null) {
				$others = $placeholder;
			} elseif ($item->ellipsis === null && is_string($item->key?->hasValue() ? $item->key->toValue() : null)) {
				$keys[$placeholder] = $item->key;
			} else {
				return;
			}
		}

		foreach ($bindings->takenApart as [$original, $otherItems, $order]) {
			$named = array_values(array_unique(array_filter(array_column($order, 0), fn(string $name) => $name !== $otherItems)));
			if ($others !== $otherItems || count($named) !== count($keys) || array_diff($named, array_keys($keys)) !== []) {
				continue;
			}

			$copy = $original->withoutEdgeTrivia();
			foreach ($copy->items->getItems() as $index => $item) {
				$key = $keys[$order[$index][0]] ?? null;
				if ($key !== null && $item instanceof ArrayItemNode) {
					$item->key?->replaceWithExpression($key->withoutEdgeTrivia());
				}
			}

			$template->replaceWithExpression($copy);
			return;
		}
	}


	/**
	 * Writes the arguments, or the items of an array, in place of the item of a list that stood for them.
	 * @param  list<ArgumentNode>|list<ArrayItemNode>  $arguments
	 * @return SeparatedNodeList<Node>
	 */
	private static function writeArguments(?Node $placeholder, array $arguments): SeparatedNodeList
	{
		$list = $placeholder?->parent;
		assert($placeholder !== null && $list instanceof SeparatedNodeList);
		$index = $list->indexOf($placeholder);
		foreach ($arguments as $offset => $argument) {
			$list->insert($index + $offset, $argument->withoutEdgeTrivia());
		}

		$list->removeItem($placeholder);
		return $list;
	}


	/**
	 * The items of an array literal unpacked into the arguments, written as the arguments PHP makes of them: a value
	 * without a key positional, one under a string key named by it. Null where the literal says more than that: a key
	 * that is no name, an item unpacked or taken by reference, a positional one behind a named one, a comment.
	 * @return ?list<ArgumentNode>
	 */
	private static function spreadArray(ExpressionNode $array): ?array
	{
		if (!$array instanceof ArrayNode || $array->hasComment()) {
			return null;
		}

		$arguments = [];
		$named = false;
		foreach ($array->items->getItems() as $item) {
			$key = $item instanceof ArrayItemNode && $item->key?->hasValue() ? $item->key->toValue() : null;
			if (
				!$item instanceof ArrayItemNode
				|| $item->ampersand !== null
				|| $item->ellipsis !== null
				|| !$item->value instanceof ExpressionNode
				|| ($item->key !== null && !(is_string($key) && preg_match('~^[a-z_\x80-\xff][\w\x80-\xff]*$~Di', $key)))
				|| ($item->key === null && $named)
			) {
				return null;
			}

			$named = $named || $item->key !== null;
			$call = (new Parser)->parseExpression($item->key === null ? 'f(0)' : "f($key: 0)");
			assert($call instanceof FunctionCallNode && $call->arguments->items->getItems()[0] instanceof ArgumentNode);
			$argument = $call->arguments->items->getItems()[0];
			$argument->value->replaceWithExpression($item->value->withoutEdgeTrivia());
			$arguments[] = $argument;
		}

		return $arguments;
	}


	/**
	 * PHP takes no positional argument behind a named one, which is where `...` after a named item may have written one.
	 * @param  SeparatedNodeList<Node>  $list
	 */
	private static function movePositionalFirst(SeparatedNodeList $list): void
	{
		$named = null;
		foreach ($list->getItems() as $index => $item) {
			if (!$item instanceof ArgumentNode) {
				continue;
			} elseif ($item->name !== null) {
				$named ??= $index;
			} elseif ($named !== null) {
				$list->removeItem($item);
				$list->insert($named++, $item);
			}
		}
	}


	/** A call of a bare name, which the template means as a member of what the replaced call was made on. */
	private static function isBareCall(FunctionCallNode $call): bool
	{
		return $call->name instanceof NameNode && $call->name->isUnqualified();
	}


	/** Whether the node stands where PHP evaluates only sometimes, or later. */
	private static function isLazy(Node $node): bool
	{
		for (; $node->parent !== null; $node = $node->parent) {
			$parent = $node->parent;
			if (
				$parent instanceof ClosureNode
				|| $parent instanceof ArrowFunctionNode
				|| $parent instanceof MatchArmNode
				|| ($parent instanceof TernaryNode && $node !== $parent->condition)
				|| ($parent instanceof BinaryOpNode && $node === $parent->right && in_array(strtolower($parent->operator->text), ['??', '&&', '||', 'and', 'or'], true))
				|| (($parent instanceof MethodCallNode || $parent instanceof PropertyFetchNode) && $node !== $parent->object && self::isNullsafeChain($parent))
				|| ($parent instanceof ArrayAccessNode && $node !== $parent->expression && self::isNullsafeChain($parent->expression))
			) {
				return true;
			}
		}

		return false;
	}


	/** Whether the chain has a nullsafe step, past which PHP evaluates nothing on null. */
	private static function isNullsafeChain(ExpressionNode $chain): bool
	{
		while ($chain instanceof MethodCallNode || $chain instanceof PropertyFetchNode || $chain instanceof ArrayAccessNode) {
			if ($chain instanceof ArrayAccessNode) {
				$chain = $chain->expression;
			} elseif ($chain->isNullsafe()) {
				return true;
			} else {
				$chain = $chain->object;
			}
		}

		return false;
	}
}
