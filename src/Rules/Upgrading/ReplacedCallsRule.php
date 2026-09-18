<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, MemberKind, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Tristate, Values, Violation};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, ArrayItemNode, DestructuringNode, ExpressionNode, IdentifierNode, NameNode, SeparatedNodeList, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, ArrayNode, AssignmentByReferenceNode, AssignmentNode, BinaryOpNode, CombinedAssignmentNode, EmptyNode, IssetNode, MethodCallNode, NewNode, PostfixOpNode, PrefixOpNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode, VariableNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\{ExpressionStatementNode, ForeachNode, UnsetNode};
use function count;


/**
 * A tool for a member that is used differently now: the project, or a library it stands on, maps a use of a member
 * to the expression written instead, and the rule rewrites every use of that shape. Whose member a use reaches is
 * decided by the type of what it is made on, as replacedMembers decides it.
 *
 * A key is a MemberPattern, a method or an instantiation with the shape of its arguments (ArgumentPattern),
 * `Class::name($a, true)` or `Class::__construct($a)`, and `Class::name()` a call without any, unlike in replacedMembers,
 * where the parentheses only mark a method; a call that fits no key is left alone, and of the keys it fits the most
 * specific one decides. A call that unpacks its arguments fits only a key taking the rest, `Class::name(...$args)`,
 * and is reported where the keys of its member name the arguments one by one. A positional item of a key takes an
 * argument passed by the name of its parameter, which only a declaration of the method tells, so a call passing one
 * by name to a method the library removed is reported too.
 * The value is the expression written instead, with the placeholders of the key: a call of a bare name is a call on
 * what the replaced call was made on, `addAlbum($name, $label)`, `$this` the same thing as an expression,
 * `\Acme\Events::dispatch($this->listeners)`, a qualified name a function or a class of its own,
 * `\Closure::fromCallable($callable)`, the whole name with the leading backslash or without it, as is the bare
 * name of a class, and an operator is written as one, `getOption($key) ?? $default`. A value
 * whose call a key takes again, and that one another, back to the first, is an error of the configuration: the
 * rewrite would never end, `is(...$kinds)` as `is([...$kinds])`.
 *
 * A property is a key by its hook, `Class::$name::get` for the expression a read becomes, `isPaid()`, and
 * `Class::$name::set` for the one an assignment does, `setPaid($value)`, a static one the call on the class it is
 * reached through, `self::$container` as `self::getContainer()`. And a magic method is a key for the syntax PHP calls
 * it by: `__get($name)` is a read of a property no class declares, `__set($name, $value)` an assignment to one,
 * `__isset()` and `__unset()` what their names say, and `offsetGet($key)`, `offsetSet($key, $value)`, `offsetExists()`
 * and `offsetUnset()` the same for `$object[$key]`, `offsetSet(null, $value)` being `$object[] = $value`. An assignment
 * is rewritten where it is a statement, its value being otherwise used, `isset()` and `unset()` where they hold nothing
 * else, and what writes the property any other way is reported and left as it is; a read on the left of `??` is risky,
 * PHP asking there whether the property is set before it reads it. That a function takes its argument by reference is
 * not seen, so a property passed to one is read as any other. In an interpolation of a string the expression is written
 * only where it is a variable and what is read or called on it, in braces, and reported otherwise. A first-class
 * callable of a key that takes no arguments becomes an arrow function of the expression, `fn() => $token->line`, where
 * what it is made on is a variable or a class, which reads the same when the closure is made as when it is called; any
 * other is reported and left as it is, and so is a callable written as a value, `[$token, 'getLine']`, where the types
 * tell its class.
 *
 * The fix is not risky where every argument is evaluated once, as before. Where the expression written instead
 * evaluates one only sometimes, not at all, or two of them in another order, the use is risky unless evaluating
 * the argument does nothing; where it would evaluate one twice, the use is reported and left as it is. A method
 * a child declares under the name of a key that takes any arguments is reported too: the method is replaced as
 * a whole there, and a declaration is nothing an expression could stand for.
 */
#[RuleInfo(
	Stage::Structure,
	typesRequired: true,
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.replacedCalls'],
	reads: [self::ReplacedMembers, self::ForbiddenMembers],
	analyses: [Types::class, NameResolver::class],
)]
final class ReplacedCallsRule extends NodeRule
{
	public const Map = 'upgrading.libraries.replacedCalls';
	private const ReplacedMembers = 'upgrading.libraries.replacedMembers';
	private const ForbiddenMembers = 'upgrading.libraries.forbiddenMembers';

	/** @var MemberMap<CallTemplate> */
	private MemberMap $map;

	/** @var MemberMap<mixed>  which renames a member a callable reaches, its arguments being no shape to bind */
	private MemberMap $members;

	/** @var MemberMap<mixed>  whose property is not what a magic method stands for */
	private MemberMap $forbiddenMembers;


	public function configure(Values $values): void
	{
		$this->map = MemberMap::fromValues($values, self::Map, CallTemplate::fromEntry(...));
		$this->members = MemberMap::fromValues($values, self::ReplacedMembers);
		$this->forbiddenMembers = MemberMap::fromValues($values, self::ForbiddenMembers);
	}


	public function getVisitedNodes(): array
	{
		return [
			MethodCallNode::class,
			StaticMethodCallNode::class,
			NewNode::class,
			PropertyFetchNode::class,
			StaticPropertyFetchNode::class,
			ArrayAccessNode::class,
			AssignmentNode::class,
			IssetNode::class,
			UnsetNode::class,
			MethodNode::class,
			ArrayNode::class,
			StringNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		match (true) {
			$node instanceof MethodCallNode, $node instanceof StaticMethodCallNode, $node instanceof NewNode => $this->enterCall($node, $context),
			$node instanceof PropertyFetchNode, $node instanceof StaticPropertyFetchNode, $node instanceof ArrayAccessNode => $this->enterAccess($node, $context),
			$node instanceof AssignmentNode => $this->enterAssignment($node, $context),
			$node instanceof IssetNode, $node instanceof UnsetNode => $this->enterIssetOrUnset($node, $context),
			$node instanceof MethodNode => $this->enterDeclaration($node, $context),
			$node instanceof ArrayNode, $node instanceof StringNode => $this->enterCallableValue($node, $context),
			default => null,
		};
	}


	private function enterCall(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): void
	{
		if (!$node instanceof NewNode && $node->arguments->isPartialApplication()) {
			$this->enterCallable($node, $context);
			return;
		}

		$found = $this->map->findCall($node, $context);
		if ($found === null) {
			$this->reportUnbound($node, $context);
			return;
		}

		[$pattern, $template, $bindings, $access] = [$found->pattern, $found->value, $found->bindings, $found->access];
		assert($bindings !== null);
		$types = $context->getAnalysis(Types::class);
		$arguments = $node->arguments ?? (new Builder)->arguments([]);
		$rewrite = match (true) {
			$node instanceof MethodCallNode => $template->instantiate($bindings, $arguments, $node->object, nullsafe: $node->nullsafe),
			$node instanceof StaticMethodCallNode => $template->instantiate($bindings, $arguments, $node->class, static: true),
			default => $template->instantiate($bindings, $arguments, null),
		};
		if ($access->kind === MemberKind::Constructor) {
			$created = $node instanceof NewNode ? $types->findMemberAccess($node)->classes ?? [] : [];
			$rewrite = self::keepConstructorCall($rewrite, $pattern, $node, ofChild: array_any($created, fn(string $class) => strcasecmp($class, $pattern->class) !== 0));
		} elseif (
			$access->kind === MemberKind::StaticMethod
			&& array_any($access->classes, fn(string $class) => strcasecmp($class, $pattern->class) !== 0)
			&& array_any($rewrite->classes, fn(NameNode $name) => $name->parent instanceof NewNode && strcasecmp(ltrim($name->text, '\\'), $pattern->class) === 0)
		) {
			// a static factory creates the class it is called through, the replacement the one it names
			$rewrite = new Rewrite(null, ', but it is called through a child, which the replacement does not create');
		}

		$message = self::describeCall($node, $access, $pattern, $template);
		$rewrite = self::fitPlace($node, $rewrite);
		if ($rewrite->isWrittenAlready($node, $context)) {
			return;
		}

		if ($rewrite->report($node instanceof NewNode ? $node->class : $node->name, $message, $context)) {
			$node->replaceWithExpression($rewrite->write($node, $context));
		}
	}


	/**
	 * A first-class callable is written as an arrow function of the expression, where the key takes no arguments and
	 * what the callable is made on reads the same when the closure is made as when it is called; reported otherwise.
	 */
	private function enterCallable(MethodCallNode|StaticMethodCallNode $node, RuleContext $context): void
	{
		if (!$node->name instanceof IdentifierNode || $this->map->getEntries(strtolower($node->name->text)) === []) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findMemberAccess($node);
		$entry = $access === null ? null : $this->findEntry($access, $types);
		if ($access === null || $entry === null || $this->members->has($access, $types)) {
			return;
		}

		[$pattern, $template] = $entry;
		$receiver = $node instanceof MethodCallNode ? $node->object : $node->class;
		$items = $node->arguments->items->getItems();
		$rewrite = match (true) {
			count($items) !== 1 || !$items[0] instanceof VariadicPlaceholderNode,
			$pattern->arguments?->items !== [] => new Rewrite(null, ', but a first-class callable gets its arguments only when called'),
			$receiver instanceof ExpressionNode && !($receiver instanceof VariableNode && $receiver->plainName !== null)
				=> new Rewrite(null, ', but a closure would read its object only when called'),
			default => $template->instantiate(new ArgumentBindings([]), (new Builder)->arguments([]), $receiver, static: $node instanceof StaticMethodCallNode),
		};

		if ($rewrite->report($node->name, self::describeCall($node, $access, $pattern, $template), $context)) {
			$node->replaceWithExpression((new Builder)->expression('fn() => $value', value: $rewrite->write($node, $context)));
		}
	}


	/** The message of a call replaced by the template, the member named by the kind the call reaches it by. */
	private static function describeCall(
		MethodCallNode|StaticMethodCallNode|NewNode $node,
		MemberAccess $access,
		MemberPattern $pattern,
		CallTemplate $template,
	): string
	{
		return $pattern->describeAccess($access, $node) . ' is replaced by ' . Violation::formatCode($template->code);
	}


	/**
	 * A call the keys of its member take for no shape, for a reason the code does not settle, is said rather than passed
	 * over: it unpacks its arguments, which the keys name one by one, or it passes one by name, which only a declaration
	 * of the method ties to the position of a key, and the library removed the method.
	 */
	private function reportUnbound(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): void
	{
		$name = MemberMaps::findLookupName($node);
		$entries = $name === null ? [] : $this->map->getEntries($name);
		$arguments = $node->arguments?->items->getItems() ?? [];
		$items = array_merge(...array_map(fn(array $entry) => $entry[0]->arguments->items ?? [], $entries));
		// a map writing unpacked arguments itself may have written the call, and a key taking what is left takes it
		$unpacks = array_any($arguments, fn(Node $argument) => $argument instanceof ArgumentNode && $argument->ellipsis !== null)
			&& !array_any($entries, fn(array $entry) => $entry[1]->hasUnpackedArgument() || $entry[0]->arguments === null)
			&& !array_any($items, fn(ArgumentPatternItem $item) => $item->rest);
		$named = array_any($arguments, fn(Node $argument) => $argument instanceof ArgumentNode && $argument->name !== null)
			&& array_any($items, fn(ArgumentPatternItem $item) => !$item->rest && $item->parameterName === null);
		if (!$unpacks && !$named) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findConstructorAccess($node) ?? $types->findMemberAccess($node);
		$entry = $access === null ? null : $this->findEntry($access, $types);
		$reason = match (true) {
			$access === null || $entry === null => null,
			$unpacks => 'the call unpacks arguments the keys name one by one',
			$types->findParameters($access) === null => 'no declaration is left to place its named argument',
			default => null, // the names are known, and the call is of no shape the keys have
		};
		if ($reason !== null) {
			assert($access !== null && $entry !== null);
			$context->report($node instanceof NewNode ? $node->class : $node->name, self::describeCall($node, $access, ...$entry) . ", but $reason.", fixable: false);
		}
	}


	/**
	 * The most specific entry of the member the access reaches, whatever the shape of the arguments; null for none.
	 * @return ?array{MemberPattern, CallTemplate}
	 */
	private function findEntry(MemberAccess $access, Types $types): ?array
	{
		return MemberMaps::findDecidingEntry(
			$this->map->getEntries(strtolower($access->name)),
			$types,
			fn(array $entry) => $entry[0]->matches($access, $types),
			specificFirst: true,
		);
	}


	/** A callable written as a value, `[$object, 'name']` or `'Acme\Order::name'`, which no expression can stand for. */
	private function enterCallableValue(ArrayNode|StringNode $node, RuleContext $context): void
	{
		$callable = CallableLiteral::find($node);
		if ($callable === null || $this->map->getEntries(strtolower($callable->method)) === []) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findCallableMethodAccess($node);
		$entry = $access === null ? null : $this->findEntry($access, $types);
		if ($access !== null && $entry !== null && !$this->members->has($access, $types)) {
			$context->report(
				$callable->literal,
				$entry[0]->describe($access->kind) . ' is replaced by ' . Violation::formatCode($entry[1]->code) . ', but no expression stands for a callable value.',
				fixable: false,
			);
		}
	}


	/**
	 * The code of a constructor written as `new Class(...)` of the class of the key stands for the call as the code makes
	 * it: the instantiation keeps the class it creates, a child that declares no constructor among them, and
	 * `parent::__construct()` stays a call of it; any other code stands only for an instantiation of the class itself.
	 * @param  bool  $ofChild  the instantiation creates a child of the class
	 */
	private static function keepConstructorCall(
		Rewrite $rewrite,
		MemberPattern $pattern,
		MethodCallNode|StaticMethodCallNode|NewNode $node,
		bool $ofChild,
	): Rewrite
	{
		$new = $rewrite->expression;
		if (
			!$new instanceof NewNode
			|| !$new->class instanceof NameNode
			|| strcasecmp(ltrim($new->class->text, '\\'), $pattern->class) !== 0
		) {
			return match (true) {
				$new === null => $rewrite,
				$node instanceof StaticMethodCallNode => new Rewrite(null, ', but `parent::__construct()` creates no object to replace'),
				$ofChild => new Rewrite(null, ', but it creates a child, which the replacement does not'),
				default => $rewrite,
			};
		}

		$classes = array_values(array_filter($rewrite->classes, fn(NameNode $class) => $class !== $new->class));
		if ($node instanceof StaticMethodCallNode) {
			$builder = new Builder;
			$arguments = $new->arguments ?? $builder->arguments([]);
			$new->arguments = null;
			return new Rewrite($builder->staticMethodCall($node->class, '__construct', $arguments), because: $rewrite->because, classes: $classes);
		}

		assert($node instanceof NewNode);
		$new->class = $node->class->withoutEdgeTrivia();
		return new Rewrite($new, because: $rewrite->because, classes: $classes);
	}


	/** A read of a property or of an offset; what writes it is rewritten where the writing stands, or reported here. */
	private function enterAccess(PropertyFetchNode|StaticPropertyFetchNode|ArrayAccessNode $node, RuleContext $context): void
	{
		$use = self::findUse($node);
		$found = $use === null ? null : $this->findTemplate($node, 'get', [], $context);
		if ($found === null) {
			return;
		}

		[$message, $template, $bindings, $arguments] = $found;
		$rewrite = match (true) {
			$use === 'written' => new Rewrite(null, ', but it is written to, which a call cannot be'),
			$template === null => new Rewrite(null, ', but nothing says what a read of it becomes'),
			default => $template->instantiate(
				$bindings,
				$arguments,
				self::getReceiver($node),
				static: $node instanceof StaticPropertyFetchNode,
				nullsafe: $node instanceof PropertyFetchNode && $node->nullsafe,
			),
		};
		if ($use === 'guarded' && $rewrite->expression !== null) {
			$rewrite = $rewrite->withRisk('the replacement no longer asks first whether it is set, as `??` does');
		}

		$rewrite = self::fitPlace($node, $rewrite);
		if ($rewrite->report($node instanceof ArrayAccessNode ? $node->openBracket : $node->name, $message, $context)) {
			$node->replaceWithExpression($rewrite->write($node, $context));
		}
	}


	private function enterAssignment(AssignmentNode $node, RuleContext $context): void
	{
		$target = $node->target;
		$found = $target instanceof PropertyFetchNode || $target instanceof StaticPropertyFetchNode || $target instanceof ArrayAccessNode
			? $this->findTemplate($target, 'set', [$node->expression], $context)
			: null;
		if ($found === null) {
			return;
		}

		[$message, $template, $bindings, $arguments] = $found;
		$rewrite = match (true) {
			$template === null => new Rewrite(null, ', but nothing says what an assignment to it becomes'),
			!$node->parent instanceof ExpressionStatementNode => new Rewrite(null, ', but the value of the assignment is used'),
			default => $template->instantiate($bindings, $arguments, self::getReceiver($target), static: $target instanceof StaticPropertyFetchNode),
		};
		if ($rewrite->report($target instanceof ArrayAccessNode ? $target->openBracket : $target->name, $message, $context)) {
			$node->replaceWithExpression($rewrite->write($node, $context));
		}
	}


	private function enterIssetOrUnset(IssetNode|UnsetNode $node, RuleContext $context): void
	{
		foreach ($node->variables as $variable) {
			$found = $variable instanceof PropertyFetchNode || $variable instanceof ArrayAccessNode
				? $this->findTemplate($variable, $node instanceof IssetNode ? 'isset' : 'unset', [], $context)
				: null;
			if ($found === null) {
				continue;
			}

			[$message, $template, $bindings, $arguments] = $found;
			$receiver = self::getReceiver($variable);
			$rewrite = match (true) {
				$template === null => new Rewrite(null, ', but nothing says what ' . ($node instanceof IssetNode ? '`isset()`' : '`unset()`') . ' of it becomes'),
				count($node->variables) > 1 => new Rewrite(null, ', but the ' . ($node instanceof IssetNode ? '`isset()`' : '`unset()`') . ' holds other arguments too'),
				default => $template->instantiate($bindings, $arguments, $receiver, nullsafe: $variable instanceof PropertyFetchNode && $variable->nullsafe),
			};
			// isset() gives false for a null object, which PHPStan does not see in the chain of a `?->` inside it
			if ($node instanceof IssetNode && $rewrite->expression !== null) {
				$rewrite = match (true) {
					$variable->isInNullsafeChain() => $rewrite->withRisk('the replacement gives null for a null object, where `isset()` gives false'),
					$receiver instanceof ExpressionNode
					&& $context->getAnalysis(Types::class)->isOfType($receiver, 'object', at: $node) !== Tristate::Yes
						=> $rewrite->withRisk('the replacement fails on a null object, where `isset()` gives false'),
					default => $rewrite,
				};
			}

			if (!$rewrite->report($variable instanceof PropertyFetchNode ? $variable->name : $variable->openBracket, $message, $context)) {
				continue;
			} elseif ($node instanceof IssetNode) {
				$node->replaceWithExpression($rewrite->write($node, $context));
			} else {
				$node->replaceWith((new Builder)->statement('$value;', value: $rewrite->write($node, $context)));
			}
		}
	}


	private function enterDeclaration(MethodNode $node, RuleContext $context): void
	{
		$entries = $this->map->getEntries(strtolower($node->name->text));
		$entry = $entries === [] ? null : MemberMaps::findDeclarationEntry($entries, $node, $context->getAnalysis(Types::class), anyArguments: true, specificFirst: true);
		if ($entry !== null) {
			$kind = $node->modifiers->static ? MemberKind::StaticMethod : MemberKind::Method;
			$context->report(
				$node->name,
				$entry[0]->describe($kind) . ' is replaced by ' . Violation::formatCode($entry[1]->code) . ', but a declaration is nothing an expression could stand for.',
				fixable: false,
			);
		}
	}


	/**
	 * What the map says of a property or an offset used the given way: the property of a key, else the magic method
	 * PHP calls for it, with the name or the key and the values as its arguments. The message, the template or null
	 * where the key has none for that use, and what the template is instantiated with; null where the map says nothing.
	 * @param  'get'|'set'|'isset'|'unset'  $use
	 * @param  list<ExpressionNode>  $values  what the magic method gets besides the name or the key
	 * @return ?array{string, ?CallTemplate, ArgumentBindings, ArgumentListNode}
	 */
	private function findTemplate(
		PropertyFetchNode|StaticPropertyFetchNode|ArrayAccessNode $node,
		string $use,
		array $values,
		RuleContext $context,
	): ?array
	{
		$name = $node instanceof ArrayAccessNode ? null : MemberMaps::findLookupName($node);
		$properties = $name === null ? [] : $this->map->getEntries($name);
		$methods = $node instanceof StaticPropertyFetchNode ? [] : $this->map->getEntries(strtolower(MagicCall::getMethod($node, $use)));
		if (($properties === [] && $methods === []) || (!$node instanceof ArrayAccessNode && $name === null)) {
			return null; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$values = array_map(fn(ExpressionNode $value) => $value->withoutEdgeTrivia(), $values);
		if (!$node instanceof ArrayAccessNode) {
			$access = $types->findMemberAccess($node);
			if ($access === null) {
				return null;
			}

			// the hooks of the property the map knows; a use through another one, isset() or unset(), is told of them
			$hooks = [];
			foreach (['get', 'set'] as $hook) {
				$hooks[$hook] = MemberMaps::findDecidingEntry(
					$properties,
					$types,
					fn(array $entry) => $entry[0]->kind === MemberKind::Property && $entry[0]->hook === $hook && $entry[0]->matches($access, $types),
					specificFirst: true,
				);
			}

			$known = $hooks['get'] ?? $hooks['set'] ?? null;
			if ($known !== null) {
				$template = $hooks[$use][1] ?? null;
				$arguments = (new Builder)->arguments($values);
				$bound = $arguments->findArgument(null, 0);
				return [
					$known[0]->describe($access->kind) . ' is replaced by ' . Violation::formatCode(($template ?? $known[1])->code),
					$template,
					new ArgumentBindings($bound === null ? [] : ['value' => $bound]),
					$arguments,
				];
			}

			if ($this->forbiddenMembers->has($access, $types)) {
				return null; // a property forbiddenMembers names is not what a magic method stands for
			}

		}

		$call = $methods === [] ? null : MagicCall::find($node, $use, $values, $types);
		if ($call === null) {
			return null;
		}

		$bindings = [];
		$entry = MemberMaps::findDecidingEntry(
			$methods,
			$types,
			function (array $entry) use ($call, $types, &$bindings): bool {
				$bound = $call->bind($entry[0], $types);
				if ($bound !== null) {
					$bindings[spl_object_id($entry[0])] = $bound;
				}

				return $bound !== null;
			},
			specificFirst: true,
		);
		return $entry === null
			? null
			: [
				$entry[0]->describe(MemberKind::Method) . ' is replaced by ' . Violation::formatCode($entry[1]->code),
				$entry[1],
				$bindings[spl_object_id($entry[0])],
				$call->arguments,
			];
	}


	/**
	 * How the property or the offset is used where it stands: read, read on the left of `??`, which asks whether it is
	 * set before it reads, written in a way this rule rewrites where the writing stands (null), or written in a way
	 * no call can be.
	 * @return 'read'|'guarded'|'written'|null
	 */
	private static function findUse(PropertyFetchNode|StaticPropertyFetchNode|ArrayAccessNode $node): ?string
	{
		$parent = $node->parent;
		if (($parent instanceof AssignmentNode && $parent->target === $node) || MagicCall::findIssetOrUnsetUse($node) !== null) {
			return null;
		}

		// what is written to may be an element of it, or an item of a destructuring
		$written = $node;
		while (
			($parent instanceof ArrayAccessNode && $parent->expression === $written)
			|| $parent instanceof DestructuringNode
			|| $parent instanceof ArrayNode
			|| $parent instanceof ArrayItemNode
			|| $parent instanceof SeparatedNodeList
		) {
			[$written, $parent] = [$parent, $parent->parent];
		}

		if ($parent instanceof BinaryOpNode && $parent->operator->text === '??' && $parent->left === $written) {
			return 'guarded';
		}

		return match (true) {
			$parent instanceof AssignmentNode,
			$parent instanceof CombinedAssignmentNode => $parent->target === $written,
			$parent instanceof AssignmentByReferenceNode,
			$parent instanceof PrefixOpNode,
			$parent instanceof PostfixOpNode,
			$parent instanceof IssetNode,
			$parent instanceof UnsetNode,
			$parent instanceof EmptyNode => true,
			$parent instanceof ForeachNode => $parent->key === $written || $parent->value === $written,
			$parent instanceof ArgumentNode => $parent->ampersand !== null,
			default => false,
		} ? 'written' : 'read';
	}


	private static function getReceiver(PropertyFetchNode|StaticPropertyFetchNode|ArrayAccessNode $node): ExpressionNode|NameNode
	{
		return match (true) {
			$node instanceof PropertyFetchNode => $node->object,
			$node instanceof StaticPropertyFetchNode => $node->class,
			default => $node->expression,
		};
	}


	/** The rewrite of a use, refused where its place cannot take the expression, as in a string. */
	private static function fitPlace(ExpressionNode $node, Rewrite $rewrite): Rewrite
	{
		if ($rewrite->expression === null) {
			return $rewrite;
		}

		try {
			$node->checkReplaceWithExpression($rewrite->expression);
		} catch (\InvalidArgumentException $e) {
			return new Rewrite(null, ', but ' . lcfirst(rtrim($e->getMessage(), '.')));
		}

		return $rewrite;
	}
}
