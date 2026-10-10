<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode, TraitUseNode};
use function count;


/**
 * Members of a class in a configured order of kinds (by default trait uses, then enum cases, constants and properties by
 * visibility); members of kinds not in the order follow the ordered ones in the order written. A class where
 * a comment other than a doc comment would move with a member is left as it is, the comment possibly heading a section.
 */
#[RuleInfo(Stage::Structure)]
final class MemberOrderRule extends NodeRule
{
	private const Kinds = [
		'traitUse', 'enumCase', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
		'property', 'publicProperty', 'protectedProperty', 'privateProperty',
		'publicStaticProperty', 'protectedStaticProperty', 'privateStaticProperty',
		'method', 'publicMethod', 'protectedMethod', 'privateMethod', 'publicStaticMethod', 'protectedStaticMethod', 'privateStaticMethod',
		'constructor', 'destructor', 'magicMethod',
	];
	private const Path = 'classes.members.order';

	/** @var list<string> */
	private array $order = [
		'traitUse', 'enumCase', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
		'publicProperty', 'protectedProperty', 'privateProperty',
	];


	public static function getDecisions(): array
	{
		$kinds = [];
		foreach (self::Kinds as $kind) {
			$words = strtolower((string) preg_replace('~[A-Z]~', ' $0', $kind));
			$kinds[$kind] = match ($kind) {
				'enumCase' => 'a case of an enum',
				'constant', 'property', 'method' => "a $words of any visibility",
				'constructor', 'destructor' => "the $words",
				'magicMethod' => 'a method whose name begins with `__`',
				default => "a $words",
			};
		}

		return [
			new Decision(self::Path, new Names($kinds, ordered: true), 'The order of the members of a class by kind, a member taking the most particular kind listed and the kinds not listed following in the order written'),
		];
	}


	public function configure(Values $values): void
	{
		$this->order = $values->get(self::Path)->getNames();
	}


	public function getVisitedNodes(): array
	{
		return [ClassLikeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassLikeNode) {
			return;
		}

		$members = $node->members->getItems();
		$sorted = [];
		foreach ($members as $i => $member) {
			$sorted[] = [$this->rank($member), $i, $member];
		}

		usort($sorted, fn($a, $b) => $a[0] <=> $b[0]);
		$misplaced = null;
		foreach ($sorted as $i => [, , $member]) {
			if ($member !== $members[$i]) {
				$misplaced = [$members[$i], $member];
				break;
			}
		}

		if ($misplaced === null) {
			return;
		}

		$movesComment = self::movesComment($members, $sorted);
		if (!$context->report(
			$misplaced[0],
			'The ' . $this->describeKind($misplaced[1]) . ' must come before the ' . $this->describeKind($misplaced[0])
			. ($movesComment ? ', but a comment would move.' : '.'),
			fixable: !$movesComment,
		)) {
			return;
		}

		foreach ($members as $member) {
			$node->members->removeItem($member);
		}

		foreach ($sorted as [, , $member]) {
			$node->members->append($member);
		}
	}


	/** The kind the member is ordered by, in words: `public static property`, `trait use`. */
	private function describeKind(Node $member): string
	{
		$kinds = self::classifyMember($member);
		$kind = $this->order[$this->rank($member)] ?? $kinds[count($kinds) - 1] ?? 'member';
		return strtolower((string) preg_replace('~[A-Z]~', ' $0', $kind));
	}


	/**
	 * Whether a member that would follow another one than it does carries a comment other than its doc comment, which
	 * may head a section of the class rather than belong to the member, and would move with it.
	 * @param  list<Node>  $members
	 * @param  list<array{int, int, Node}>  $sorted  rank, original index and member
	 */
	private static function movesComment(array $members, array $sorted): bool
	{
		foreach ($sorted as $i => [, $index, $member]) {
			if (($members[$index - 1] ?? null) !== ($sorted[$i - 1][2] ?? null)) {
				$doc = $member->getDocComment();
				foreach ($member->getFirstToken()?->getLeadingComments() ?? [] as $comment) {
					if ($comment !== $doc) {
						return true;
					}
				}
			}
		}

		return false;
	}


	private function rank(Node $member): int
	{
		foreach (self::classifyMember($member) as $kind) {
			$position = array_search($kind, $this->order, strict: true);
			if ($position !== false) {
				return $position;
			}
		}

		return count($this->order);
	}


	/**
	 * Kinds of a member from the most specific to the most general.
	 * @return list<string>
	 */
	private static function classifyMember(Node $member): array
	{
		if ($member instanceof TraitUseNode) {
			return ['traitUse'];
		} elseif ($member instanceof EnumCaseNode) {
			return ['enumCase'];
		}

		[$base, $modifiers] = match (true) {
			$member instanceof ClassConstNode => ['constant', $member->modifiers],
			$member instanceof PropertyNode => ['property', $member->modifiers],
			$member instanceof MethodNode => ['method', $member->modifiers],
			default => [null, null],
		};
		if ($base === null || $modifiers === null) {
			return [];
		}

		$visibility = strtolower($modifiers->visibility->name);
		$kinds = [];
		if ($member instanceof MethodNode) {
			$name = strtolower($member->name->token->text);
			$kinds = match ($name) {
				'__construct' => ['constructor', 'magicMethod'],
				'__destruct' => ['destructor', 'magicMethod'],
				default => str_starts_with($name, '__') ? ['magicMethod'] : [],
			};
		}

		if ($modifiers->static) {
			$kinds[] = $visibility . 'Static' . ucfirst($base);
		}

		$kinds[] = $visibility . ucfirst($base);
		$kinds[] = $base;
		return $kinds;
	}
}
