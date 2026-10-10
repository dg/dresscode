<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberKind, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ClassLikeNode, IdentifierNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, ArrayNode, AssignmentNode, ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyItemNode, PropertyNode};
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * Uses of the members a project, or a library it stands on, says its code must not have, each reported with what
 * the map says to do instead; nothing is rewritten, which is what the map is for where no expression could stand
 * for the member. Whose member a use reaches is decided by the type of what it is made on, as replacedMembers
 * decides it, so a member the library has removed is found too.
 *
 * A key spells a member the way PHP reads it: `Class::name` is a constant or a method, `Class::name(...$args)` a
 * method, `Class::$name` a property, `Class::$name::get` a read of it and `Class::$name::set` a write, as the hooks of
 * a property divide its uses, `Class::__construct(...$args)` an instantiation, and a method or an instantiation with
 * the shape of its arguments, `Class::hash($password, $options)` or `Class::date()`, only a call of that shape. Empty
 * parentheses are a shape too, a call without arguments, so the key of a method called with any is
 * `Class::name(...$args)`; in `replacedMembers` the parentheses only mark a method. A closure made of the method,
 * `$object->name(...)`, and a callable naming it, `[$object, 'name']`, are of a key that takes any arguments. A method
 * a child declares under the name of a key that takes any arguments is reported too, and so is a property it declares
 * under the name of a key without a hook, the declaration being a use of the member as much as a call is. A magic
 * method is a key for the syntax PHP calls it by, as in `replacedCalls`: `__get($name)` is a read of a property no
 * class declares and `offsetSet(null, $value)` is `$object[] = $value`.
 */
#[RuleInfo(
	Stage::Structure,
	typesRequired: true,
	analyses: [Types::class, NameResolver::class],
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.forbiddenMembers'],
)]
final class ForbiddenMembersRule extends NodeRule
{
	public const Map = 'upgrading.libraries.forbiddenMembers';

	/** @var MemberMap<string>  the entries with the end of the message */
	private MemberMap $map;


	public function configure(Values $values): void
	{
		$this->map = MemberMap::fromValues($values, self::Map, fn(?string $message) => $message === null ? '' : ": $message");
	}


	public function getVisitedNodes(): array
	{
		return [
			ClassConstantFetchNode::class,
			MethodCallNode::class,
			StaticMethodCallNode::class,
			PropertyFetchNode::class,
			StaticPropertyFetchNode::class,
			NewNode::class,
			ArrayAccessNode::class,
			MethodNode::class,
			PropertyNode::class,
			ParameterNode::class,
			ArrayNode::class,
			StringNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof MethodNode) {
			$this->enterDeclaration($node, $context);
			return;
		} elseif ($node instanceof PropertyNode || $node instanceof ParameterNode) {
			$this->enterPropertyDeclaration($node, $context);
			return;
		} elseif ($node instanceof ArrayAccessNode) {
			$this->enterMagic($node, $context);
			return;
		} elseif ($node instanceof ArrayNode || $node instanceof StringNode) {
			$this->enterCallableValue($node, $context);
			return;
		} elseif (
			!$node instanceof ClassConstantFetchNode
			&& !$node instanceof MethodCallNode
			&& !$node instanceof StaticMethodCallNode
			&& !$node instanceof PropertyFetchNode
			&& !$node instanceof StaticPropertyFetchNode
			&& !$node instanceof NewNode
		) {
			return;
		}

		// the types are asked only about a name the map knows
		$name = MemberMaps::findLookupName($node);
		$entries = $name === null ? [] : $this->map->getEntries($name);
		if (!$this->reportMember($node, $entries, $context) && $node instanceof PropertyFetchNode) {
			$this->enterMagic($node, $context);
		}
	}


	/**
	 * Reports the use when it is one of the member of an entry, and says whether it was.
	 * @param  list<array{MemberPattern, string}>  $entries
	 */
	private function reportMember(
		ClassConstantFetchNode|MethodCallNode|StaticMethodCallNode|PropertyFetchNode|StaticPropertyFetchNode|NewNode $node,
		array $entries,
		RuleContext $context,
	): bool
	{
		$types = $entries === [] ? null : $context->getAnalysis(Types::class);
		$access = $types?->findConstructorAccess($node) ?? $types?->findMemberAccess($node);
		if ($types === null || $access === null) {
			return false;
		}

		$arguments = $node instanceof MethodCallNode || $node instanceof StaticMethodCallNode || $node instanceof NewNode
			? $node->arguments ?? (new Builder)->arguments([])
			: null;
		$use = $node instanceof PropertyFetchNode || $node instanceof StaticPropertyFetchNode ? self::findHookUse($node) : 'get';
		$entry = MemberMaps::findDecidingEntry(
			$entries,
			$types,
			fn(array $entry) => $entry[0]->matches($access, $types)
				&& $entry[0]->matchesHook($use)
				&& ($entry[0]->arguments === null || ($arguments !== null && ($arguments->isPartialApplication()
					? $entry[0]->arguments->takesAnyArguments()
					: $entry[0]->arguments->bind($arguments, $types->findParameters($access), $types) !== null))),
			specificFirst: $arguments !== null,
		);
		if ($entry === null) {
			return false;
		}

		[$pattern, $message] = $entry;
		$member = $pattern->describeAccess($access, $node);
		$described = match ($pattern->hook) {
			'get' => 'Reading ' . lcfirst($member),
			'set' => 'Writing to ' . lcfirst($member),
			default => $member,
		};
		$context->report($node instanceof NewNode ? $node->class : $node->name, "$described is forbidden$message.", fixable: false);
		return true;
	}


	/**
	 * How the code uses the property, as the hooks of it see the use: `isset()` and `unset()` for themselves, a write
	 * for anything else that writes it, an assignment, a step, a destructuring or a loop, a read for the rest, a write
	 * of an element of it among them, which reads the property it writes into.
	 * @return 'get'|'set'|'isset'|'unset'
	 */
	private static function findHookUse(PropertyFetchNode|StaticPropertyFetchNode $node): string
	{
		$element = $node->parent instanceof ArrayAccessNode && $node->parent->expression === $node;
		return MagicCall::findIssetOrUnsetUse($node) ?? (!$element && $node->isWritten() ? 'set' : 'get');
	}


	/** A property no class declares, or an offset, by the magic method PHP calls for it. */
	private function enterMagic(PropertyFetchNode|ArrayAccessNode $node, RuleContext $context): void
	{
		$parent = $node->parent;
		[$use, $values] = match (true) {
			$parent instanceof AssignmentNode && $parent->target === $node => ['set', [$parent->expression->withoutEdgeTrivia()]],
			default => [MagicCall::findIssetOrUnsetUse($node) ?? 'get', []],
		};
		$entries = $this->map->getEntries(strtolower(MagicCall::getMethod($node, $use)));
		if ($entries === [] || ($node instanceof PropertyFetchNode && !$node->name instanceof IdentifierNode)) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$call = MagicCall::find($node, $use, $values, $types);
		$entry = $call === null
			? null
			: MemberMaps::findDecidingEntry($entries, $types, fn(array $entry) => $call->bind($entry[0], $types) !== null, specificFirst: true);
		if ($entry !== null) {
			$context->report(
				$node instanceof PropertyFetchNode ? $node->name : $node->openBracket,
				$entry[0]->describe(MemberKind::Method) . " is forbidden$entry[1].",
				fixable: false,
			);
		}
	}


	/** A callable written as a value, `[$object, 'name']` or `'Acme\Order::name'`, of a key that takes any arguments. */
	private function enterCallableValue(ArrayNode|StringNode $node, RuleContext $context): void
	{
		$callable = CallableLiteral::find($node);
		$entries = $callable === null ? [] : $this->map->getEntries(strtolower($callable->method));
		if ($callable === null || $entries === []) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findCallableMethodAccess($node);
		$entry = $access === null
			? null
			: MemberMaps::findDecidingEntry($entries, $types, fn(array $entry) => $entry[0]->takesAnyArguments() && $entry[0]->matches($access, $types));
		if ($access !== null && $entry !== null) {
			$context->report($callable->literal, $entry[0]->describe($access->kind) . " is forbidden$entry[1].", fixable: false);
		}
	}


	private function enterDeclaration(MethodNode $node, RuleContext $context): void
	{
		$entries = $this->map->getEntries(strtolower($node->name->text));
		$entry = $entries === [] ? null : MemberMaps::findDeclarationEntry($entries, $node, $context->getAnalysis(Types::class), anyArguments: true);
		if ($entry !== null) {
			$kind = $node->modifiers->static ? MemberKind::StaticMethod : MemberKind::Method;
			$context->report($node->name, $entry[0]->describe($kind) . " is forbidden$entry[1].", fixable: false);
		}
	}


	private function enterPropertyDeclaration(PropertyNode|ParameterNode $node, RuleContext $context): void
	{
		$declared = $node instanceof PropertyNode
			? array_map(fn(PropertyItemNode $item) => [$item->plainName, $item->name], $node->items->getItems())
			: ($node->promoted ? [[(string) $node->variable->plainName, $node->variable]] : []);
		$declared = array_filter($declared, fn(array $property) => $this->map->getEntries(strtolower($property[0])) !== []);
		$classLike = $declared === [] ? null : $node->findAncestor(ClassLikeNode::class);
		$class = $classLike === null ? null : $context->getAnalysis(NameResolver::class)->getDeclaredName($classLike);
		if ($class === null) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$kind = $node->modifiers->static ? MemberKind::StaticProperty : MemberKind::Property;
		foreach ($declared as [$name, $at]) {
			$entry = MemberMaps::findDecidingEntry(
				$this->map->getEntries(strtolower($name)),
				$types,
				fn(array $entry) => $entry[0]->matchesPropertyDeclaration($class, $name, $types),
			);
			if ($entry !== null) {
				$context->report($at, $entry[0]->describe($kind) . " is forbidden$entry[1].", fixable: false);
			}
		}
	}
}
