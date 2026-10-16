<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
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
	/** the member => what its place of `classes.members.grouping` covers */
	private const Places = [
		'constant' => 'A declaration of several constants, `const A = 1, B = 2;`, a `const` outside a class included',
		'property' => 'A declaration of several properties, `public $a, $b;`',
		'traitUse' => 'A `use` of several traits without adaptations, `use A, B;`',
	];

	/** @var array<string, bool>  the member => whether its declaration is split */
	private array $separated = [];


	public static function getDecisions(): array
	{
		$separated = new Words(['separated' => 'one member per declaration, each sharing the modifiers and the type of the original']);
		return array_map(
			fn(string $place, string $description) => new Decision("classes.members.grouping.$place", $separated, $description),
			array_keys(self::Places),
			self::Places,
		);
	}


	public function configure(Values $values): void
	{
		foreach (array_keys(self::Places) as $place) {
			$this->separated[$place] = !$values->isKept("classes.members.grouping.$place");
		}
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
			|| !$this->separated[$member]
			|| !$list instanceof PlainNodeList
			|| count($items) < 2
			|| $items->hasInnerComment()
			|| !$context->report(
				$node,
				"Expected one $kind per $where, " . count($items) . ' found.',
				decision: "classes.members.grouping.$member",
			)
		) {
			return;
		}

		NodeHelpers::splitItems($node, $list, $node instanceof TraitUseNode ? 'traits' : 'items', $context->style->lineEnding);
	}
}
