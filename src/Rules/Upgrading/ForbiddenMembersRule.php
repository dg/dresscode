<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberKind, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Upgrading\{MemberMap, MemberMapEntry, MemberMapGrammar};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ClassLikeNode, IdentifierNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{
	ArrayAccessNode, ArrayNode, AssignmentNode, ClassConstantFetchNode, MethodCallNode,
	NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode,
};
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
	decisions: ['upgrading.packages', 'upgrading.php.deprecatedApi', 'upgrading.api.forbiddenMembers'],
)]
final class ForbiddenMembersRule extends NodeRule
{
	public const Map = MemberMapGrammar::ForbiddenMembers;

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
		$name = MemberMap::findLookupName($node);
		if (!$this->reportMember($node, $name, $context) && $node instanceof PropertyFetchNode) {
			$this->enterMagic($node, $context);
		}
	}


	/**
	 * Reports the use when it is one of the member of an entry, and says whether it was.
	 * @param  ?string  $name  the lowercased name of the member, null where it is an expression
	 */
	private function reportMember(
		ClassConstantFetchNode|MethodCallNode|StaticMethodCallNode|PropertyFetchNode|StaticPropertyFetchNode|NewNode $node,
		?string $name,
		RuleContext $context,
	): bool
	{
		$types = $name === null || $this->map->getEntries($name) === [] ? null : $context->getAnalysis(Types::class);
		$access = $types?->findConstructorAccess($node) ?? $types?->findMemberAccess($node);
		if ($types === null || $access === null) {
			return false;
		}

		$arguments = $node instanceof MethodCallNode || $node instanceof StaticMethodCallNode || $node instanceof NewNode
			? $node->arguments ?? (new Builder)->arguments([])
			: null;
		$use = $node instanceof PropertyFetchNode || $node instanceof StaticPropertyFetchNode ? self::findHookUse($node) : 'get';
		$entry = $this->map->findDecidingEntry(
			(string) $name,
			$types,
			fn(MemberMapEntry $entry) => $entry->pattern->matches($access, $types)
				&& $entry->pattern->matchesHook($use)
				&& ($entry->pattern->arguments === null || ($arguments !== null && ($arguments->isPartialApplication()
					? $entry->pattern->arguments->takesAnyArguments()
					: $entry->pattern->arguments->bind($arguments, $types->findParameters($access), $types) !== null))),
			specificFirst: $arguments !== null,
		);
		if ($entry === null) {
			return false;
		}

		$pattern = $entry->pattern;
		$member = $pattern->describeAccess($access, $node);
		$described = match ($pattern->hook) {
			'get' => 'Reading ' . lcfirst($member),
			'set' => 'Writing to ' . lcfirst($member),
			default => $member,
		};
		$context->report(
			$node instanceof NewNode ? $node->class : $node->name,
			"$described " . self::formatVerdict($entry, $types->knowsMember($pattern->class, $access->kind, $access->name)),
			decision: $entry->decision,
			source: $entry->origin,
			fixable: false,
		);
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
		$method = strtolower(MagicCall::getMethod($node, $use));
		if ($this->map->getEntries($method) === [] || ($node instanceof PropertyFetchNode && !$node->name instanceof IdentifierNode)) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$call = MagicCall::find($node, $use, $values, $types);
		$entry = $call === null
			? null
			: $this->map->findDecidingEntry(
				$method,
				$types,
				fn(MemberMapEntry $entry) => $call->bind($entry->pattern, $types) !== null,
				specificFirst: true,
			);
		if ($entry !== null) {
			$context->report(
				$node instanceof PropertyFetchNode ? $node->name : $node->openBracket,
				$entry->pattern->describe(MemberKind::Method)
				. ' '
				. self::formatVerdict($entry, $types->knowsMember($entry->pattern->class, MemberKind::Method, $method)),
				decision: $entry->decision,
				source: $entry->origin,
				fixable: false,
			);
		}
	}


	/** A callable written as a value, `[$object, 'name']` or `'Acme\Order::name'`, of a key that takes any arguments. */
	private function enterCallableValue(ArrayNode|StringNode $node, RuleContext $context): void
	{
		$callable = CallableLiteral::find($node);
		$method = $callable === null ? null : strtolower($callable->method);
		if ($method === null || $this->map->getEntries($method) === []) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findCallableMethodAccess($node);
		$entry = $access === null
			? null
			: $this->map->findDecidingEntry(
				$method,
				$types,
				fn(MemberMapEntry $entry) => $entry->pattern->takesAnyArguments() && $entry->pattern->matches($access, $types),
			);
		if ($access !== null && $entry !== null) {
			$context->report(
				$callable->literal,
				$entry->pattern->describe($access->kind)
				. ' '
				. self::formatVerdict($entry, $types->knowsMember($entry->pattern->class, $access->kind, $access->name)),
				decision: $entry->decision,
				source: $entry->origin,
				fixable: false,
			);
		}
	}


	private function enterDeclaration(MethodNode $node, RuleContext $context): void
	{
		if ($this->map->getEntries($node->name->text) === []) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$entry = $this->map->findDeclaration($node, $types, anyArguments: true);
		if ($entry !== null) {
			$kind = $node->modifiers->static ? MemberKind::StaticMethod : MemberKind::Method;
			$exists = $types->knowsMember($entry->pattern->class, $kind, $entry->pattern->name);
			$context->report(
				$node->name,
				$entry->pattern->describe($kind) . ' ' . self::formatVerdict($entry, $exists),
				decision: $entry->decision,
				source: $entry->origin,
				fixable: false,
			);
		}
	}


	private function enterPropertyDeclaration(PropertyNode|ParameterNode $node, RuleContext $context): void
	{
		$declared = $node instanceof PropertyNode
			? array_map(fn(PropertyItemNode $item) => [$item->plainName, $item->name], $node->items->getItems())
			: ($node->promoted ? [[(string) $node->variable->plainName, $node->variable]] : []);
		$declared = array_filter($declared, fn(array $property) => $this->map->getEntries($property[0]) !== []);
		$classLike = $declared === [] ? null : $node->findAncestor(ClassLikeNode::class);
		$class = $classLike === null ? null : $context->getAnalysis(NameResolver::class)->getDeclaredName($classLike);
		if ($class === null) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$kind = $node->modifiers->static ? MemberKind::StaticProperty : MemberKind::Property;
		foreach ($declared as [$name, $at]) {
			$entry = $this->map->findDecidingEntry(
				strtolower($name),
				$types,
				fn(MemberMapEntry $entry) => $entry->pattern->matchesPropertyDeclaration($class, $name, $types),
			);
			if ($entry !== null) {
				$exists = $types->knowsMember($entry->pattern->class, $kind, $name);
				$context->report(
					$at,
					$entry->pattern->describe($kind) . ' ' . self::formatVerdict($entry, $exists),
					decision: $entry->decision,
					source: $entry->origin,
					fixable: false,
				);
			}
		}
	}


	/**
	 * What the entry says of the use, with the end of its message: a member the data of a package retired is deprecated
	 * while the installed version still has it and removed once it has not, one of the project is forbidden.
	 * @param  MemberMapEntry<string>  $entry
	 */
	private static function formatVerdict(MemberMapEntry $entry, bool $exists): string
	{
		return 'is ' . Retirement::of($entry->origin !== null, $exists)->value . $entry->value . '.';
	}
}
