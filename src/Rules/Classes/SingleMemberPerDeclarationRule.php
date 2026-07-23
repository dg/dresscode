<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\{ClassConstNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\ConstNode;
use function count, in_array;


/**
 * One member per declaration: `const A = 1, B = 2;`, `public $a, $b;` and `use T1, T2;` become one
 * declaration per constant, property or trait, each sharing the modifiers and the type of the original.
 * A trait use with adaptations in braces stays as it is; a `const` outside a class counts as a constant.
 */
#[RuleInfo(
	'dresscode/single-member-per-declaration',
	Stage::Structure,
	description: 'Splits a declaration of several constants, properties or traits into one per member',
)]
final class SingleMemberPerDeclarationRule extends NodeRule implements ConfigurableRule
{
	/** @var list<string> */
	private array $members = ['constant', 'property', 'trait'];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'members' => Expect::listOf(Expect::anyOf('constant', 'property', 'trait'))->default(['constant', 'property', 'trait'])
				->description('Kinds of members to split; constant also covers a const outside a class'),
		]);
	}


	public function configure(array $options): void
	{
		$this->members = $options['members'];
	}


	public function getVisitedTypes(): array
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

		[$kind, $message] = match (true) {
			$node instanceof PropertyNode => ['property', 'One property per declaration'],
			$node instanceof TraitUseNode => ['trait', 'One trait per use statement'],
			default => ['constant', 'One constant per declaration'],
		};
		$list = $node->parent;
		if (
			($node instanceof TraitUseNode && $node->openBrace !== null)
			|| !in_array($kind, $this->members, true)
			|| !$list instanceof NodeList
			|| count($node instanceof TraitUseNode ? $node->traits : $node->items) < 2
			|| !$context->report($node, $message)
		) {
			return;
		}

		NodeHelpers::splitItems($node, $list, $node instanceof TraitUseNode ? 'traits' : 'items', $context->getStyle()->eol);
	}
}
