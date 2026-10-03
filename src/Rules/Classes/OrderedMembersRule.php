<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode, TraitUseNode};
use function count;


/**
 * Members of a class in a configured order of kinds (by default trait uses, then constants and properties by
 * visibility); members of kinds not in the order follow the ordered ones in the order written.
 */
#[RuleInfo(
	'dresscode/orderedMembers',
	Stage::Structure,
	description: 'Orders class members by kind and visibility',
)]
final class OrderedMembersRule extends NodeRule implements ConfigurableRule
{
	private const Kinds = [
		'traitUse', 'case', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
		'property', 'publicProperty', 'protectedProperty', 'privateProperty',
		'publicStaticProperty', 'protectedStaticProperty', 'privateStaticProperty',
		'method', 'publicMethod', 'protectedMethod', 'privateMethod', 'publicStaticMethod', 'protectedStaticMethod', 'privateStaticMethod',
		'constructor', 'destructor', 'magicMethod',
	];

	/** @var list<string> */
	private array $order = [
		'traitUse', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
		'publicProperty', 'protectedProperty', 'privateProperty',
	];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'order' => Expect::listOf(Expect::anyOf(...self::Kinds))->default([
				'traitUse', 'constant', 'publicConstant', 'protectedConstant', 'privateConstant',
				'publicProperty', 'protectedProperty', 'privateProperty',
			])->description('Kinds of members in the required order; a member takes the most specific kind listed, unlisted kinds follow in the order written'),
		]);
	}


	public function configure(array $options): void
	{
		$this->order = $options['order'];
	}


	public function getVisitedTypes(): array
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

		usort($sorted, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
		$first = null;
		foreach ($sorted as $i => [, , $member]) {
			if ($member !== $members[$i]) {
				$first = $members[$i];
				break;
			}
		}

		if ($first === null || !$context->report($first, 'Class members are not in the configured order')) {
			return;
		}

		foreach ($members as $member) {
			$node->members->removeItem($member);
		}

		foreach ($sorted as [, , $member]) {
			$node->members->append($member);
		}
	}


	private function rank(Node $member): int
	{
		foreach (self::kindsOf($member) as $kind) {
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
	private static function kindsOf(Node $member): array
	{
		if ($member instanceof TraitUseNode) {
			return ['traitUse'];
		} elseif ($member instanceof EnumCaseNode) {
			return ['case'];
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
			$kinds[] = match ($name) {
				'__construct' => 'constructor',
				'__destruct' => 'destructor',
				default => str_starts_with($name, '__') ? 'magicMethod' : 'method',
			};
		}

		if ($modifiers->isStatic()) {
			$kinds[] = $visibility . 'Static' . ucfirst($base);
		}

		$kinds[] = $visibility . ucfirst($base);
		$kinds[] = $base;
		return $kinds;
	}
}
