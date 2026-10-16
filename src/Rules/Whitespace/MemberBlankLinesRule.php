<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, RuleInfo, Stage, Values};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, Member, MemberNode, PlainNodeList, Statement};
use function count;


/**
 * How many blank lines stand around the members of a class: between methods, between properties, constants and enum
 * cases, around the trait uses, and after the opening and before the closing brace. Comments stay with the member
 * below the blank lines, and a doc comment counts as part of what it documents.
 */
#[RuleInfo(Stage::Formatting, decisions: ['blankLines.afterPhpdoc'])]
final class MemberBlankLinesRule extends GapRule
{
	private const ClassLikes = [
		Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
		AnonymousClassNode::class,
	];
	private const Counts = [
		'aroundMethods' => 'Before and after a method; the first and the last member of a class take `bodyStart.class` and `bodyEnd.class`',
		'aroundInterfaceMethods' => 'Before and after a method of an interface, in place of `aroundMethods`',
		'bodyStart.class' => 'After the opening brace of a class, an interface, a trait or an enum, before its first member',
		'bodyStart.anonymousClass' => 'After the opening brace of an anonymous class, before its first member',
		'bodyEnd.class' => 'Before the closing brace of a class, an interface, a trait or an enum, after its last member',
		'bodyEnd.anonymousClass' => 'Before the closing brace of an anonymous class, after its last member',
		'betweenTraitUses' => 'Between the trait uses of a class',
		'afterTraitUses' => 'Before the first member after the trait uses, a method included',
		'betweenMemberKinds' => 'Between a property, a constant and an enum case of different kinds, the lower one without a doc comment or an attribute',
		'betweenMembers' => 'Between two properties, two constants or two enum cases, the lower one without a doc comment or an attribute',
		'beforeDocumentedMember' => 'Before a property, constant or enum case with a doc comment or an attribute',
		'afterPhpdoc' => null,
	];

	/** @var array<string, int|array{int, ?int}|null>  the decision of a count => its count, null where it is kept */
	private array $counts = [];

	private readonly BlankLineClaims $claims;


	public function __construct()
	{
		$this->claims = new BlankLineClaims;
	}


	public static function getDecisions(): array
	{
		$decisions = [];
		foreach (array_filter(self::Counts) as $key => $description) {
			$decisions[] = new Decision("blankLines.$key", Domain::blankLines(), $description);
		}

		return $decisions;
	}


	public function configure(Values $values): void
	{
		foreach (array_keys(self::Counts) as $key) {
			$this->counts[$key] = BlankLineClaims::readCount($values->get("blankLines.$key"));
		}
	}


	public function getClaims(): array
	{
		$claims = [];
		foreach (self::ClassLikes as $class) {
			$claims[$class] = [
				'members:item' => [
					fn(Gap $gap) => $this->claimBeforeMember($gap->value, $gap->index ?? 0),
					fn(Gap $gap) => $this->claimAfterMethod($gap->value, $gap->index ?? 0),
				],
				'closeBrace' => [fn(Gap $gap) => $this->claimAfterLastMember($gap->token), null],
			];
		}

		return $claims;
	}


	/** Before a member of a class, by what it is and what stands above it. */
	private function claimBeforeMember(Node|Token $member, int $index): ?Claim
	{
		$list = $member->parent;
		if (!$member instanceof MemberNode || !$list instanceof PlainNodeList) {
			return null;
		}

		$previous = $list->getItems()[$index - 1] ?? null;
		$documented = $member instanceof Member\PropertyNode
			|| $member instanceof Member\ClassConstNode
			|| $member instanceof Member\EnumCaseNode
			? !$member->attributes->isEmpty() || $member->getDocComment() !== null
			: false;
		$key = match (true) {
			$index === 0 => 'bodyStart.' . self::findPlace($list),
			$member instanceof Member\MethodNode => match (true) {
				$previous instanceof Member\TraitUseNode => 'afterTraitUses',
				default => self::chooseBetweenMethods($list),
			},
			$member instanceof Member\TraitUseNode => $previous instanceof Member\TraitUseNode ? 'betweenTraitUses' : null,
			$previous instanceof Member\TraitUseNode => 'afterTraitUses',
			$previous instanceof Member\MethodNode => null, // the method claims the gap after itself
			$documented => 'beforeDocumentedMember',
			$previous instanceof $member => 'betweenMembers',
			default => 'betweenMemberKinds',
		};
		$below = BlankLineClaims::findBelowDocComment($member, $this->counts['afterPhpdoc']);
		return $this->claims->claim($key === null ? null : $this->counts[$key], $key ?? 'afterPhpdoc', $below, 'afterPhpdoc');
	}


	/** After a method, before a member that is not a method or before the closing brace of the class. */
	private function claimAfterMethod(Node|Token $member, int $index): ?Claim
	{
		$list = $member->parent;
		if (!$member instanceof Member\MethodNode || !$list instanceof PlainNodeList) {
			return null;
		}

		$next = $list->getItems()[$index + 1] ?? null;
		if ($next instanceof Member\MethodNode) {
			return null; // the method below claims the gap before itself
		}

		$key = $next === null ? 'bodyEnd.' . self::findPlace($list) : self::chooseBetweenMethods($list);
		return $this->claims->claim($this->counts[$key], $key);
	}


	/** Before the closing brace of a class, unless a method stands last and claims the gap after itself. */
	private function claimAfterLastMember(Token $brace): ?Claim
	{
		$class = $brace->parent;
		if (!$class instanceof ClassLikeNode) {
			return null;
		}

		$members = $class->members->getItems();
		$key = 'bodyEnd.' . self::findPlace($class->members);
		return ($members[count($members) - 1] ?? null) instanceof Member\MethodNode
			? null
			: $this->claims->claim($this->counts[$key], $key);
	}


	/**
	 * The place of `bodyStart` and `bodyEnd` the class of the members is.
	 * @param  PlainNodeList<MemberNode>  $members
	 */
	private static function findPlace(PlainNodeList $members): string
	{
		return $members->parent instanceof AnonymousClassNode ? 'anonymousClass' : 'class';
	}


	/**
	 * The decision of the count between the methods of the class.
	 * @param  PlainNodeList<MemberNode>  $members
	 */
	private static function chooseBetweenMethods(PlainNodeList $members): string
	{
		return $members->parent instanceof Statement\InterfaceNode ? 'aroundInterfaceMethods' : 'aroundMethods';
	}
}
