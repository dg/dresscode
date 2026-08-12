<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\{Shapes, Words};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NamedTypeNode, UnionTypeNode};
use function count;


/**
 * A nullable native type is written `?Foo`, not `Foo|null`; in a longer union `null` stands at one end, or where
 * it is written, and the other types may be asked to come in alphabetical order. The case of `null` is the matter
 * of `BuiltinNameCasingRule`, spaces around `|` of `TypeDeclarationSpacingRule`.
 */
#[RuleInfo(Stage::Structure)]
final class TypeNotationRule extends NodeRule
{
	private const ByName = 'byName';
	private const Nullable = 'types.nullable';
	private const NullPosition = 'types.nullPosition';
	private const UnionOrder = 'types.unionOrder';

	private bool $shortNullable = true;

	/** where `null` stands in a union, null where it is kept */
	private ?string $nullPosition = 'last';

	/** the order of the other types, null where it is kept */
	private ?string $order = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Nullable, new Shapes(['questionMark' => ['?int', 'a single type with `null` written with `?`']]), 'How a native type of one type and `null` is written'),
			new Decision(self::NullPosition, new Words(['last' => '`int|string|null`', 'first' => '`null|int|string`']), 'Where `null` stands in a native union type'),
			new Decision(self::UnionOrder, new Words([self::ByName => 'the types beside `null` sorted by name, case-insensitively, an intersection by its first name']), 'The order of the types of a native union'),
		];
	}


	public function configure(Values $values): void
	{
		$this->shortNullable = !$values->isKept(self::Nullable);
		$this->nullPosition = $values->isKept(self::NullPosition) ? null : $values->get(self::NullPosition)->getWord();
		$this->order = $values->isKept(self::UnionOrder) ? null : $values->get(self::UnionOrder)->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [UnionTypeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnionTypeNode || $node->hasInnerComment()) {
			return;
		}

		$members = $node->types->getItems();
		$nulls = $others = $actual = [];
		$nullIndex = 0;
		$dnf = false;
		foreach ($members as $member) {
			$dnf = $dnf || $member instanceof IntersectionTypeNode;
			if ($member instanceof NamedTypeNode && strtolower($member->name->text) === 'null') {
				$nulls[] = $member;
				$nullIndex = count($actual);
				$actual[] = 'null';
			} else {
				$actual[] = $others[] = $member->text;
			}
		}

		if ($others === []) {
			return;
		}

		if ($nulls !== [] && $this->shortNullable && count($others) === 1 && !$dnf) {
			if ($context->report($node, 'The nullable type must be written ' . Violation::formatCode('?' . $others[0]) . '.', decision: self::Nullable)) {
				$node->replaceWith((new Builder)->type('?' . $others[0]));
			}

			return;
		}

		if ($this->order === null && ($nulls === [] || $this->nullPosition === null)) {
			return;
		}

		if ($this->order === self::ByName) {
			usort($others, fn(string $a, string $b) => strcasecmp(ltrim($a, '('), ltrim($b, '('))); // (A&B) sorts by A
		}

		$expected = $others;
		if ($nulls !== []) {
			$index = match ($this->nullPosition) {
				'first' => 0,
				'last' => count($others),
				default => $nullIndex,
			};
			array_splice($expected, $index, 0, 'null');
		}

		if ($actual === $expected) {
			return;
		}

		[$message, $decision] = $this->nullPosition === null
			|| ($this->order === self::ByName && array_values(array_diff($actual, ['null'])) !== $others)
				? ['The types of a union type must be in alphabetical order.', self::UnionOrder]
				: ["`null` must come {$this->nullPosition} in a union type.", self::NullPosition];
		if ($context->report($node, $message, decision: $decision)) {
			$node->replaceWith((new Builder)->type(implode('|', $expected)));
		}
	}
}
