<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\{ClassConstNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\ConstNode;
use function count;


/**
 * One member per declaration: `const A = 1, B = 2;`, `public $a, $b;` and `use T1, T2;` become one
 * declaration per constant, property or trait, each sharing the modifiers and the type of the original.
 * A trait use with adaptations in braces stays as it is, and so does a declaration with a comment between its
 * members, which would have nowhere to go; a `const` outside a class counts as a constant.
 */
#[RuleInfo(Stage::Structure)]
final class NoGroupedDeclarationsRule extends NodeRule
{
	private const Path = 'classes.groupedDeclarationAllowedFor';

	/** @var list<string>  the kinds whose declaration may stay grouped */
	private array $grouped = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Path, new Names([
				'constant' => 'a constant, a `const` outside a class included',
				'property' => 'a property',
				'traitUse' => 'a trait of a `use` without adaptations',
			]), 'The kinds of members whose declaration may stay grouped, as `public $a, $b;` is; a declaration of several members of any other kind is split into one per member, each sharing the modifiers and the type of the original, so `[]` splits every kind, while `keep` leaves every declaration as it is'),
		];
	}


	public function configure(Values $values): void
	{
		$this->grouped = $values->get(self::Path)->getNames();
	}


	public function getVisitedNodes(): array
	{
		return [ClassConstNode::class, ConstNode::class, PropertyNode::class, TraitUseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstNode
			&& !$node instanceof ConstNode
			&& !$node instanceof PropertyNode
			&& !$node instanceof TraitUseNode
		) {
			return;
		}

		[$member, $kind, $where] = match (true) {
			$node instanceof PropertyNode => ['property', 'property', 'declaration'],
			$node instanceof TraitUseNode => ['traitUse', 'trait', '`use` statement'],
			default => ['constant', 'constant', 'declaration'],
		};
		$list = $node->parent;
		$items = $node instanceof TraitUseNode ? $node->traits : $node->items;
		if (
			($node instanceof TraitUseNode && $node->openBrace !== null)
			|| in_array($member, $this->grouped, true)
			|| !$list instanceof PlainNodeList
			|| count($items) < 2
			|| $items->hasInnerComment()
			|| !$context->report($node, "Expected one $kind per $where, " . count($items) . ' found.')
		) {
			return;
		}

		NodeHelpers::splitItems($node, $list, $node instanceof TraitUseNode ? 'traits' : 'items', $context->style->lineEnding);
	}
}
