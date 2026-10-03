<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, MemberKind, Types};
use DressCode\{ConfigurableRule, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Rules\CodeWriter;
use Nette\Schema\Schema;
use PhpSyntax\{Builder, NameForm, Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayNode, ClassConstantFetchNode, FunctionCallNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{ExpressionNode, IdentifierNode, NameNode, PlainNodeList};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * A tool for a member that is called something else and used the same way: the project, or a library it stands on,
 * maps a constant, a method or a property to what is written instead, and the rule rewrites every access, every call
 * and every declaration overriding it in a child. Whose member an access reaches is decided by the type of its
 * receiver, `$form`, `self::`, `parent::` or `MyForm::` alike, so that a member the library has removed since is
 * found as well as one a child overrides; a receiver that may be of another class too is left alone.
 *
 * A key spells a member the way PHP reads it: `Class::name` is a constant or a method, `Class::name()` a method
 * whatever its call passes, the parentheses marking the kind alone, while in replacedCalls and forbiddenMembers they
 * are the shape of a call without arguments,
 * `Class::$name` a property. The value is the name alone for a member of the same class, `Other::name` for one of
 * another class, which only a static access and a constant can be moved to, and `\function` for a global function
 * a method becomes, the only change of kind there is, because the two are called the same way; the function is
 * written fully qualified, for the rules of the notation of names to spell as the project does. The arguments stay
 * as they are. A callable written as a value, `[$order, 'recalc']` or `'Acme\Shop\Order::create'`, takes the new
 * name where the types tell its class, whether or not the class still declares the method. What cannot be rewritten is reported with the reason.
 *
 * The fix is not risky, the replacement being the word of whoever wrote the map; only what is written without the
 * expression the member was reached through, a function and a member of another class, is risky where evaluating
 * that expression may do something, and a callable written as a string, which may name the method for something
 * else than a call.
 */
#[RuleInfo(
	'dresscode/replacedMembers',
	Stage::Structure,
	description: 'Writes the member a project or its libraries write instead of another one',
	typesRequired: true,
)]
final class ReplacedMembersRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, list<array{MemberPattern, MemberTarget}>>  lowercased name => the entries of that name */
	private array $byName = [];


	public static function getOptionsSchema(): Schema
	{
		return MemberMaps::map(
			MemberMaps::code(),
			'The replaced member, `Class::name` (a constant or a method), `Class::name()` (a method) or `Class::$name` (a property) → the member written instead, by its name in the same class, as `Class::name` in another, or the `\function` a method becomes',
			MemberTarget::fromCode(...),
		);
	}


	public function configure(array $options): void
	{
		$this->byName = MemberMaps::indexEntries($options, MemberTarget::fromCode(...));
	}


	public function getVisitedTypes(): array
	{
		return [
			ClassConstantFetchNode::class,
			MethodCallNode::class,
			StaticMethodCallNode::class,
			PropertyFetchNode::class,
			StaticPropertyFetchNode::class,
			MethodNode::class,
			ArrayNode::class,
			StringNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof MethodNode) {
			$this->enterDeclaration($node, $context);
			return;
		} elseif ($node instanceof ArrayNode || $node instanceof StringNode) {
			$this->enterCallable($node, $context);
			return;
		} elseif (
			!$node instanceof ClassConstantFetchNode
			&& !$node instanceof MethodCallNode
			&& !$node instanceof StaticMethodCallNode
			&& !$node instanceof PropertyFetchNode
			&& !$node instanceof StaticPropertyFetchNode
		) {
			return;
		}

		// the types are asked only about a name the map knows
		$name = MemberMaps::findLookupName($node);
		$entries = $name === null ? [] : $this->byName[$name] ?? [];
		if ($entries === []) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findMemberAccess($node);
		$entry = $access === null ? null : MemberMaps::findEntry($entries, $access, $types);
		if (
			$access === null
			|| $entry === null
			|| (($node instanceof MethodCallNode || $node instanceof StaticMethodCallNode) && $context->findRule(ReplacedCallsRule::class)?->hasCall($node, $context))
		) {
			return; // the shape of the arguments of a call is more specific than its name
		}

		$pattern = $entry->pattern;
		$target = $entry->value;
		if (!$target->isFunction && $target->class === null && $access->name === $target->name) {
			return; // a method renamed in its letter case alone, which the key finds in either
		}

		$refusal = self::findRefusal($node, $access, $target, $types);
		$replacement = $refusal === null ? self::createReplacement($node, $target) : null;
		if ($replacement !== null && !CodeWriter::canReplaceExpression($node, $replacement)) {
			$refusal = ', but it stands in a string, whose interpolation takes a variable and what is read or called on it alone';
		}

		$risk = $refusal === null ? self::findRisk($node, $target) : null;
		if (!$context->report(
			$node->name,
			$pattern->describeAccess($access, $node) . ' is replaced by ' . $target->describe($access->kind, $pattern) . $refusal,
			fixable: $refusal === null,
			risk: $risk === null ? null : Risk::BehaviorChanges,
			because: $risk,
		)) {
			return;
		}

		if ($replacement === null) {
			self::rename($node, $target->name);
			return;
		}

		if (!$replacement instanceof FunctionCallNode) {
			assert(
				($replacement instanceof ClassConstantFetchNode || $replacement instanceof StaticMethodCallNode || $replacement instanceof StaticPropertyFetchNode)
				&& $replacement->class instanceof NameNode
				&& $target->class !== null,
			);
			$replacement->class->text = $replacement->class->form === NameForm::FullyQualified ? '\\' . $target->class : CodeWriter::writeClass($target->class, $node, $context);
		}

		CodeWriter::replaceExpression($node, $replacement);
	}


	/**
	 * Whether the map has the member the access reaches, which is what a rule reading the deprecations asks to stay silent.
	 * @internal
	 */
	public function hasMember(MemberAccess $access, Types $types): bool
	{
		return array_any(
			$this->byName[strtolower($access->name)] ?? [],
			fn(array $entry) => $entry[0]->matches($access, $types),
		);
	}


	/** A method a child declares under the replaced name overrides nothing any more, so it takes the new name with its calls. */
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
			: array_find($entries, fn(array $entry) => $entry[0]->matchesMethodDeclaration($class, $node->name->text, $types));
		if ($entry === null) {
			return;
		}

		[$pattern, $target] = $entry;
		if (!$target->isFunction && $target->class === null && $node->name->text === $target->name) {
			return;
		}

		$kind = $node->modifiers->isStatic() ? MemberKind::StaticMethod : MemberKind::Method;
		$siblings = $node->parent instanceof PlainNodeList ? $node->parent->getItems() : [];
		$refusal = match (true) {
			$target->isFunction || $target->class !== null => ', but a declaration cannot move out of its class',
			array_any($siblings, fn(Node $member) => $member instanceof MethodNode && $member->name->equals($target->name))
				=> ", but the class declares `$target->name()` already",
			default => null,
		};
		if ($context->report(
			$node->name,
			$pattern->describe($kind) . ' is replaced by ' . $target->describe($kind, $pattern) . ($refusal ?? ''),
			fixable: $refusal === null,
		)) {
			$node->name->text = $target->name;
		}
	}


	/**
	 * A callable written as a value, `[$object, 'name']` or `'Acme\Order::name'`, takes the new name of the method where
	 * the types tell its class; a string is risky, as it may name the method for something else than a call.
	 */
	private function enterCallable(ArrayNode|StringNode $node, RuleContext $context): void
	{
		$callable = CallableLiteral::find($node);
		$entries = $callable === null ? [] : $this->byName[strtolower($callable->method)] ?? [];
		if ($entries === []) {
			return; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findCallableMethodAccess($node);
		$entry = $access === null ? null : MemberMaps::findEntry($entries, $access, $types);
		if ($access === null || $entry === null) {
			return;
		}

		$pattern = $entry->pattern;
		$target = $entry->value;
		if (!$target->isFunction && $target->class === null && $access->name === $target->name) {
			return;
		}

		$refusal = $target->isFunction || $target->class !== null ? ', but a callable value takes only a method of its class' : null;
		$risk = $refusal === null && $node instanceof StringNode ? 'the string may name the method for something else than a call' : null;
		if ($context->report(
			$callable->literal,
			$pattern->describe($access->kind) . ' is replaced by ' . $target->describe($access->kind, $pattern) . $refusal,
			fixable: $refusal === null,
			risk: $risk === null ? null : Risk::BehaviorChanges,
			because: $risk,
		)) {
			$callable->rename($target->name);
		}
	}


	/** Why the access cannot be rewritten, as a clause of the message; null when it can. */
	private static function findRefusal(ExpressionNode $node, MemberAccess $access, MemberTarget $target, Types $types): ?string
	{
		$isCall = $node instanceof MethodCallNode || $node instanceof StaticMethodCallNode;
		return match (true) {
			$target->isFunction && !$isCall => ', but a constant is not written as a function',
			$target->isFunction && $node instanceof MethodCallNode && $node->isNullsafe()
				=> ', but the replacement cannot skip a null as `?->` does',
			$target->class === null => null,
			$types->findClassName($target->class) === null => ", but class `$target->class` does not exist in the project",
			$access->kind === MemberKind::Method || $access->kind === MemberKind::Property
				=> ', but an object cannot be asked for a member of another class',
			$node instanceof StaticMethodCallNode
			&& $node->class instanceof NameNode
			&& $node->class->isSpecialClass()
			&& !array_all($access->classes, fn(string $class) => $types->isStaticMethod($class, $access->name) === Tristate::Yes)
				=> ", but `{$node->class->text}::` may call the method on the object, which the other class cannot",
			default => null,
		};
	}


	/** Why rewriting the access may change what the code does, as a clause of the message; null when it cannot. */
	private static function findRisk(ExpressionNode $node, MemberTarget $target): ?string
	{
		// a function and a member of another class are written without what the member was reached through
		$receiver = match (true) {
			!$target->isFunction && $target->class === null => null,
			$node instanceof MethodCallNode => $node->object,
			$node instanceof ClassConstantFetchNode,
			$node instanceof StaticMethodCallNode,
			$node instanceof StaticPropertyFetchNode => $node->class instanceof ExpressionNode ? $node->class : null,
			default => null,
		};
		return $receiver !== null && !$receiver->isRepeatableRead()
			? 'the replacement no longer evaluates what the member is reached through'
			: null;
	}


	/**
	 * The expression a function or a member of another class is written as in place of the access, the class not yet
	 * spelled the way the file reaches it; null for a member of the same class, which is renamed where it stands.
	 */
	private static function createReplacement(
		ClassConstantFetchNode|MethodCallNode|StaticMethodCallNode|PropertyFetchNode|StaticPropertyFetchNode $node,
		MemberTarget $target,
	): ?ExpressionNode
	{
		if ($target->isFunction) {
			assert($node instanceof MethodCallNode || $node instanceof StaticMethodCallNode);
			return (new Builder)->call('\\' . $target->name, $node->arguments);
		} elseif ($target->class === null) {
			return null;
		}

		assert(!$node instanceof MethodCallNode && !$node instanceof PropertyFetchNode);
		$replacement = $node->withoutEdgeTrivia();
		if (!$replacement->class instanceof NameNode) {
			$replacement->class = NameNode::fromText($target->class);
		}

		self::rename($replacement, $target->name);
		return $replacement;
	}


	private static function rename(
		ClassConstantFetchNode|MethodCallNode|StaticMethodCallNode|PropertyFetchNode|StaticPropertyFetchNode $node,
		string $name,
	): void
	{
		if ($node->name instanceof IdentifierNode) {
			$node->name->text = $name;
		} elseif ($node->name instanceof Token) {
			$node->name->setText('$' . $name);
		}
	}
}
