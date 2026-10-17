<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Types;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\{Shapes, Words};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NamedTypeNode, NullableTypeNode, UnionTypeNode};
use function count;


/**
 * A nullable native type is written `?Foo` or `Foo|null`, `null` standing where `types.nullPosition` puts it; in
 * a longer union `null` stands at one end, or where it is written, and the other types may be asked to come in
 * alphabetical order. The case of `null` is the matter
 * of `DeclaredNameCasingRule`, spaces around `|` of `TypeDeclarationSpacingRule`.
 */
#[RuleInfo(Stage::Structure)]
final class TypeNotationRule extends NodeRule
{
	private const Alphabetical = 'alphabetical';
	private const Nullable = 'types.nullable';
	private const NullPosition = 'types.nullPosition';
	private const UnionOrder = 'types.unionOrder';

	/** questionMark or union, null where it is kept */
	private ?string $nullable = 'questionMark';

	/** where `null` stands in a union, null where it is kept */
	private ?string $nullPosition = 'last';

	/** the order of the other types, null where it is kept */
	private ?string $order = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Nullable,
				new Shapes([
					'questionMark' => ['?int', 'a single type with `null` written with `?`'],
					'union' => ['int|null', 'a union with `null`, which `types.nullPosition` places'],
				]),
				'How a native type of one type and `null` is written',
			),
			new Decision(
				self::NullPosition,
				new Words(['last' => '`int|string|null`', 'first' => '`null|int|string`']),
				'Where `null` stands in a native union type',
			),
			new Decision(
				self::UnionOrder,
				new Words(
					[self::Alphabetical => 'the types beside `null` sorted by name, case-insensitively, an intersection by its first name'],
				),
				'The order of the types of a native union',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->nullable = $values->find(self::Nullable)?->getShape();
		$this->nullPosition = $values->isKept(self::NullPosition) ? null : $values->get(self::NullPosition)->getWord();
		$this->order = $values->isKept(self::UnionOrder) ? null : $values->get(self::UnionOrder)->getWord();
	}


	public function getVisitedNodes(): array
	{
		return $this->nullable === 'union' ? [UnionTypeNode::class, NullableTypeNode::class] : [UnionTypeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NullableTypeNode && !$node->hasInnerComment()) {
			$union = $this->nullPosition === 'first' ? 'null|' . $node->type->text : $node->type->text . '|null';
			if ($context->report(
				$node,
				'The nullable type must be written ' . Violation::formatCode($union) . '.',
				decision: self::Nullable,
			)) {
				$node->replaceWith((new Builder)->type($union));
			}

			return;

		} elseif (!$node instanceof UnionTypeNode || $node->hasInnerComment()) {
			return;
		}

		$members = $node->types->getItems();
		$nulls = $others = [];
		$nullIndex = 0;
		$dnf = false;
		foreach ($members as $member) {
			$dnf = $dnf || $member instanceof IntersectionTypeNode;
			if ($member instanceof NamedTypeNode && strtolower($member->name->text) === 'null') {
				$nulls[] = $member;
				$nullIndex = count($others);
			} else {
				$others[] = $member->text;
			}
		}

		if ($others === []) {
			return;
		}

		if ($nulls !== [] && $this->nullable === 'questionMark' && count($others) === 1 && !$dnf) {
			if ($context->report(
				$node,
				'The nullable type must be written ' . Violation::formatCode('?' . $others[0]) . '.',
				decision: self::Nullable,
			)) {
				$node->replaceWith((new Builder)->type('?' . $others[0]));
			}

			return;
		}

		$changed = false;
		if ($this->order === self::Alphabetical) {
			$sorted = $others;
			usort($sorted, fn(string $a, string $b) => strcasecmp(ltrim($a, '('), ltrim($b, '('))); // (A&B) sorts by A
			if (
				$sorted !== $others
				&& $context->report($node, 'The types of a union type must be in alphabetical order.', decision: self::UnionOrder)
			) {
				[$others, $changed] = [$sorted, true];
			}
		}

		$wanted = match ($this->nullPosition) {
			'first' => 0,
			'last' => count($others),
			default => $nullIndex,
		};
		if (
			$nulls !== []
			&& $wanted !== $nullIndex
			&& $context->report($node, "`null` must come {$this->nullPosition} in a union type.", decision: self::NullPosition)
		) {
			[$nullIndex, $changed] = [$wanted, true];
		}

		if ($changed) {
			if ($nulls !== []) {
				array_splice($others, $nullIndex, 0, 'null');
			}

			$node->replaceWith((new Builder)->type(implode('|', $others)));
		}
	}
}
