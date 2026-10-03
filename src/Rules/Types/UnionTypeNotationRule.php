<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleGroup, RuleInfo, Stage, Violation};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NamedTypeNode, UnionTypeNode};
use function count;


/**
 * A nullable native type is written `?Foo`, not `Foo|null`; in a longer union `null` stands at one end, and the
 * other types may be asked to come in alphabetical order. Spaces around `|` are the matter of
 * dresscode/typeHintSpacing.
 */
#[RuleInfo(
	'dresscode/unionTypeNotation',
	Stage::Structure,
	description: 'Writes a nullable type as `?T`, puts `null` last in a union type and may sort the rest',
	group: RuleGroup::Types,
)]
final class UnionTypeNotationRule extends NodeRule implements ConfigurableRule
{
	private const ByName = 'byName';
	private const Keep = 'keep';

	private bool $shortNullable = true;
	private string $nullPosition = 'last';
	private string $order = self::Keep;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'shortNullable' => Expect::bool(true)->description('`T|null` is written `?T`'),
			'nullPosition' => Expect::anyOf('last', 'first')->default('last')->description('Where `null` stands in a union of three or more types'),
			'order' => Expect::anyOf(self::ByName, self::Keep)->default(self::Keep)
				->description('`byName` sorts the types beside `null` by name, case-insensitively; `keep` leaves their order alone'),
		]);
	}


	public function configure(array $options): void
	{
		$this->shortNullable = $options['shortNullable'];
		$this->nullPosition = $options['nullPosition'];
		$this->order = $options['order'];
	}


	public function getVisitedTypes(): array
	{
		return [UnionTypeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnionTypeNode || $node->hasInnerComment()) {
			return;
		}

		$members = $node->types->getItems();
		$nulls = $others = [];
		$dnf = false;
		foreach ($members as $member) {
			$dnf = $dnf || $member instanceof IntersectionTypeNode;
			if ($member instanceof NamedTypeNode && strtolower($member->name->text) === 'null') {
				$nulls[] = $member;
			} else {
				$others[] = trim((string) $member);
			}
		}

		if ($others === [] || ($nulls === [] && $this->order === self::Keep)) {
			return;
		}

		if ($nulls !== [] && $this->shortNullable && count($others) === 1 && !$dnf) {
			if ($context->report($node, 'The nullable type must be written ' . Violation::formatCode('?' . $others[0]))) {
				$node->replaceWith((new Builder)->type('?' . $others[0]));
			}

			return;
		}

		if ($this->order === self::ByName) {
			usort($others, strcasecmp(...));
		}

		$expected = match (true) {
			$nulls === [] => $others,
			$this->nullPosition === 'last' => [...$others, 'null'],
			default => ['null', ...$others],
		};
		$actual = array_map(fn(Node $member) => trim((string) $member), $members);
		if ($actual === $expected) {
			return;
		}

		$message = $this->order === self::ByName && array_values(array_diff($actual, ['null'])) !== $others
			? 'The types of a union type must be in alphabetical order'
			: "`null` must come {$this->nullPosition} in a union type";
		if ($context->report($node, $message)) {
			$node->replaceWith((new Builder)->type(implode('|', $expected)));
		}
	}
}
