<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Deprecation, Member, MemberKind, PhpDoc, Types};
use DressCode\{CodeWriter, Decision, Domain, NodeRule, QualifiedNames, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use DressCode\Upgrading\{MemberMap, MemberMapGrammar};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};


/**
 * Constants, methods and properties of classes their declaration deprecates, `$order::STATUS_PAID` where
 * `Order::STATUS_PAID` says `@deprecated use Order::StatusPaid`, whatever the expression that reaches them: the member
 * is decided by the class declaring it. A replacement in the same class is fixed by writing its name, where the class
 * has it at least as visible, for a property at least as writable and of the same type, and it takes the use as it is,
 * a method every call of the deprecated one, and so is a static call whose replacement is a public static method of
 * another class, `Text::check($s)` becoming `Encoding::isValid($s)`, written with its class; the fix is risky, the
 * deprecation naming what to use and not saying it behaves alike. Any other is reported with what the deprecation says.
 * A member the maps of replacedMembers, replacedCalls or forbiddenMembers have is not reported, nor a use inside
 * the class declaring it, which keeps it working, nor one inside a declaration deprecated itself, which goes together with it.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, PhpDoc::class, NameResolver::class], reads: self::Maps)]
final class NoDeprecatedMembersRule extends NodeRule
{
	private const Maps = [MemberMapGrammar::ReplacedMembers, MemberMapGrammar::ReplacedCalls, MemberMapGrammar::ForbiddenMembers];
	private const Because = 'the deprecation names the replacement, not that it behaves alike';

	/** @var list<MemberMap<mixed>>  every map of members */
	private array $mapped;


	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.declarations.deprecatedMember',
			Domain::state(),
			'A constant, method or property whose declaration is `@deprecated`, replaced by the member the deprecation names, reported where it names none',
		)];
	}


	public function configure(Values $values): void
	{
		$this->mapped = array_map(fn(string $path) => MemberMap::fromValues($values, $path), self::Maps);
	}


	public function getVisitedNodes(): array
	{
		return [
			ClassConstantFetchNode::class,
			MethodCallNode::class,
			StaticMethodCallNode::class,
			PropertyFetchNode::class,
			StaticPropertyFetchNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstantFetchNode
			&& !$node instanceof MethodCallNode
			&& !$node instanceof StaticMethodCallNode
			&& !$node instanceof PropertyFetchNode
			&& !$node instanceof StaticPropertyFetchNode
		) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$member = $types->findMember($node);
		if ($member === null) {
			return;
		}

		$deprecation = $types->findDeprecation($member);
		$access = $deprecation === null ? null : $types->findMemberAccess($node);
		if (
			$deprecation === null
			|| ($access !== null && array_any($this->mapped, fn(MemberMap $map) => $map->has($access, $types)))
			|| $types->findScopeClass($node) === $member->declaringClass
			|| NodeHelpers::isInDeprecatedDeclaration($node, $context)
		) {
			return;
		}

		$replacement = self::findReplacement($member, $deprecation, $types);
		$moved = $replacement === null && $node instanceof StaticMethodCallNode && $node->class instanceof NameNode
			? self::findStaticReplacement($member, $deprecation, $node, $types)
			: null;
		$fix = $moved ?? ($replacement === null ? null : [null, $replacement]);
		$message = $member->describe() . ' is deprecated' . $deprecation->formatDescription() . '.';
		if ($fix === null) {
			$context->report($node->name, $message, fixable: false);
		} elseif ($context->report($node->name, $message, risk: Risk::BehaviorChanges, because: self::Because)) {
			[$class, $name] = $fix;
			if ($class !== null && $node instanceof StaticMethodCallNode) {
				$node->class->replaceWith(NameNode::fromText(CodeWriter::writeClass($class, $node, $context)));
			}

			$node->rename($name);
		}
	}


	/**
	 * The class and the method a static call goes to in place of the deprecated one: a public static method of the class
	 * the deprecation names, looked up beside the deprecated class first where the name is bare, that takes the call the
	 * same way, the names of the parameters counting only where the call names its arguments.
	 * @return ?array{string, string}
	 */
	private static function findStaticReplacement(
		Member $member,
		Deprecation $deprecation,
		StaticMethodCallNode $call,
		Types $types,
	): ?array
	{
		$written = $deprecation->replacementClass;
		$name = $deprecation->replacementName;
		if ($written === null || $name === null || !$deprecation->replacementIsCall || $member->kind !== MemberKind::StaticMethod) {
			return null;
		}

		$beside = ltrim(QualifiedNames::extractNamespace($member->declaringClass) . '\\' . $written, '\\');
		$class = str_contains($written, '\\')
			? $types->findClassName($written) ?? $types->findClassName($beside)
			: $types->findClassName($beside) ?? $types->findClassName($written);
		$named = array_any(
			$call->arguments->items->getItems(),
			fn(Node $item) => !$item instanceof ArgumentNode || $item->name !== null || $item->ellipsis !== null,
		);
		return $class !== null && strcasecmp($class, $member->declaringClass) !== 0
			&& $types->canReplaceByStaticMethod($member, $class, $name, $named)
			? [$class, $name]
			: null;
	}


	/** The name written in place of the member: what the deprecation names when it is a member of the same class that can replace it. */
	private static function findReplacement(Member $member, Deprecation $deprecation, Types $types): ?string
	{
		$class = $deprecation->replacementClass;
		$shortName = QualifiedNames::stripNamespace($member->declaringClass);
		$isProperty = in_array($member->kind, [MemberKind::Property, MemberKind::StaticProperty], true);
		$isCall = in_array($member->kind, [MemberKind::Method, MemberKind::StaticMethod], true);
		return $deprecation->replacementName !== null
			&& str_starts_with($deprecation->replacementName, '$') === $isProperty
			&& $deprecation->replacementIsCall === $isCall
			&& ($class === null || strcasecmp($class, $member->declaringClass) === 0 || strcasecmp($class, $shortName) === 0)
			&& $types->canReplace($member, ltrim($deprecation->replacementName, '$'))
			? ltrim($deprecation->replacementName, '$')
			: null;
	}
}
