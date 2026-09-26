<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Access, MemberKind, Types};
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, IdentifierNode, NameNode, SeparatedNodeList};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, AssignmentByReferenceNode, AssignmentNode, ClassConstantFetchNode, CombinedAssignmentNode, IssetNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * Uses of the members a project, or a library it stands on, says its code must not have, each reported with what
 * the map says to do instead; nothing is rewritten, which is what the map is for where no expression could stand
 * for the member. Whose member a use reaches is decided by the type of what it is made on, as replaced-members
 * decides it, so a member the library has removed is found too.
 *
 * A key spells a member the way PHP reads it: `Class::name` is a constant or a method, `Class::name(...$args)`
 * a method, `Class::$name` a property, `Class::$name::get` a read of it and `Class::$name::set` a write, as the hooks
 * of a property divide its uses, `Class::__construct(...$args)` an instantiation, and a method or an
 * instantiation with the shape of its arguments, `Class::hash($password, $options)` or `Class::date()`, only a call
 * of that shape. A method a child declares under the name of a key that takes any arguments is reported too, the
 * declaration being a use of the member as much as a call is. A magic method is a key for the syntax PHP calls it by, as in replaced-calls:
 * `__get($name)` is a read of a property no class declares and `offsetSet(null, $value)` is `$object[] = $value`.
 */
#[RuleInfo(
	'dresscode/forbidden-members',
	Stage::Structure,
	description: 'Reports uses and declarations of the configured members with what to do instead',
	requiresTypes: true,
)]
final class ForbiddenMembersRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, list<array{MemberPattern, string}>>  lowercased name => the entries of that name with what to do instead */
	private array $byName = [];


	public static function getOptionsSchema(): Schema
	{
		return MemberMaps::map(
			Expect::string(),
			'The forbidden member, `Class::name` (a constant or a method), `Class::name(...$args)` (a method), `Class::$name` (a property), `Class::$name::get` or `::set` (a read or a write of it), `Class::__construct(...$args)`, or a call with the shape of its arguments, `Class::name()` being one without any → what to do instead, as the end of the message',
		);
	}


	public function configure(array $options): void
	{
		$this->byName = MemberMaps::read($options, fn(string $message) => $message);
	}


	public function getVisitedTypes(): array
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
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof MethodNode) {
			$this->enterDeclaration($node, $context);
			return;
		} elseif ($node instanceof ArrayAccessNode) {
			$this->enterMagic($node, $context);
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
		$name = match (true) {
			$node instanceof NewNode => '__construct',
			$node instanceof StaticPropertyFetchNode => $node->plainName,
			$node->name instanceof IdentifierNode => $node->name->text,
			default => null,
		};
		$entries = $name === null ? [] : $this->byName[strtolower($name)] ?? [];
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
		$access = $types?->findConstructorAccess($node) ?? $types?->findAccess($node);
		if ($types === null || $access === null) {
			return false;
		}

		$isCall = $node instanceof MethodCallNode || $node instanceof StaticMethodCallNode || $node instanceof NewNode;
		$use = $node instanceof PropertyFetchNode || $node instanceof StaticPropertyFetchNode ? self::detectHookUse($node) : 'get';
		foreach (MemberMaps::order($entries, $types) as [$pattern, $message]) {
			if (
				$pattern->matches($access, $types)
				&& $pattern->matchesHook($use)
				&& ($pattern->arguments === null || ($isCall && $pattern->arguments->bind($node->arguments ?? ArgumentListNode::of(), $types->findParameters($access), $types) !== null))
			) {
				// parent::name() is written as a static call and says nothing of the method being one
				$kind = $access->kind === MemberKind::StaticMethod && $node instanceof StaticMethodCallNode && $node->class instanceof NameNode && $node->class->isSpecialClass()
					? MemberKind::Method
					: $access->kind;
				$described = match ($pattern->hook) {
					'get' => 'Reading ' . lcfirst($pattern->describe($kind)),
					'set' => 'Writing to ' . lcfirst($pattern->describe($kind)),
					default => $pattern->describe($kind),
				};
				$context->report($node instanceof NewNode ? $node->class : $node->name, "$described is forbidden: $message", fixable: false);
				return true;
			}
		}

		return false;
	}


	/**
	 * How the code uses the property, as the hooks of it see the use: a write for an assignment of any kind to it and
	 * for `unset()`, `isset()` for itself, a read for anything else.
	 * @return 'get'|'set'|'isset'|'unset'
	 */
	private static function detectHookUse(PropertyFetchNode|StaticPropertyFetchNode $node): string
	{
		$parent = $node->parent;
		return match (true) {
			($parent instanceof AssignmentNode || $parent instanceof CombinedAssignmentNode || $parent instanceof AssignmentByReferenceNode)
			&& $parent->target === $node => 'set',
			$parent instanceof SeparatedNodeList && $parent->parent instanceof IssetNode => 'isset',
			$parent instanceof SeparatedNodeList && $parent->parent instanceof UnsetNode => 'unset',
			default => 'get',
		};
	}


	/** A property no class declares, or an offset, by the magic method PHP calls for it. */
	private function enterMagic(PropertyFetchNode|ArrayAccessNode $node, RuleContext $context): void
	{
		$parent = $node->parent;
		[$use, $values] = match (true) {
			$parent instanceof AssignmentNode && $parent->target === $node => ['set', [$parent->expression->withoutEdgeTrivia()]],
			$parent instanceof SeparatedNodeList && $parent->parent instanceof IssetNode => ['isset', []],
			$parent instanceof SeparatedNodeList && $parent->parent instanceof UnsetNode => ['unset', []],
			default => ['get', []],
		};
		$entries = $this->byName[strtolower(MagicCall::getMethod($node, $use))] ?? [];
		if ($entries === [] || ($node instanceof PropertyFetchNode && !$node->name instanceof IdentifierNode)) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$call = MagicCall::find($node, $use, $values, $types);
		$entry = $call === null ? null : array_find($entries, fn(array $entry) => $call->bind($entry[0], $types) !== null);
		if ($entry !== null) {
			$context->report(
				$node instanceof PropertyFetchNode ? $node->name : $node->openBracket,
				$entry[0]->describe(MemberKind::Method) . " is forbidden: $entry[1]",
				fixable: false,
			);
		}
	}


	/** Whether the map has the member the access reaches, which is what a rule reading the deprecations asks to stay silent. */
	public function knows(Access $access, Types $types): bool
	{
		return array_any(
			$this->byName[strtolower($access->name)] ?? [],
			fn(array $entry) => $entry[0]->matches($access, $types),
		);
	}


	private function enterDeclaration(MethodNode $node, RuleContext $context): void
	{
		$entries = $this->byName[strtolower($node->name->text)] ?? [];
		if ($entries === []) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$class = $types->findDeclaringClass($node);
		$entry = $class === null
			? null
			: array_find($entries, fn(array $entry) => $entry[0]->takesAnyArguments() && $entry[0]->matchesDeclaration($class, $node->name->text, $types));
		if ($entry !== null) {
			$kind = $node->modifiers->isStatic() ? MemberKind::StaticMethod : MemberKind::Method;
			$context->report($node->name, $entry[0]->describe($kind) . " is forbidden: $entry[1]", fixable: false);
		}
	}
}
