<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Ast\{AbstractNodeVisitor, Node, NodeTraverser};
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\{ArrayShapeItemNode, ArrayTypeNode, GenericTypeNode, IdentifierTypeNode, NullableTypeNode, ObjectShapeItemNode, TypeNode, UnionTypeNode};
use function count, in_array;


/**
 * Rewrites the types of a doc comment into the notation `PhpdocTypeNotationRule` asks for, collecting what changed.
 * @internal
 */
final class PhpdocTypeNotationVisitor extends AbstractNodeVisitor
{
	private const Short = NativeType::Synonyms + [
		'callback' => 'callable',
		'real' => 'float',
		'str' => 'string',
	];

	private const Builtin = [
		'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null',
		'object', 'parent', 'resource', 'scalar', 'self', 'static', 'string', 'true', 'void',
	];

	/** @var list<array{string, string}>  the message and the decision of each */
	public array $messages = [];

	/** @var \SplObjectStorage<Node, null>  shape keys are names, not types */
	private \SplObjectStorage $keys;


	public function __construct(
		/** @var \Closure(string): bool  whether the name stands for a class */
		private readonly \Closure $isClass,
		private readonly bool $canonical,
		private readonly bool $shortNullable,
		private readonly ?string $nullPosition,
		private readonly bool $byName,
		private readonly ?string $arrayNotation,
	) {
		$this->keys = new \SplObjectStorage;
	}


	/** @return Node|NodeTraverser::DONT_TRAVERSE_CHILDREN|null */
	public function enterNode(Node $node): Node|int|null
	{
		if ($node instanceof DoctrineTagValueNode) {
			return NodeTraverser::DONT_TRAVERSE_CHILDREN; // annotation arguments are values, not types
		}

		if (
			(
				$node instanceof ArrayShapeItemNode
				|| $node instanceof ObjectShapeItemNode
			)
			&& $node->keyName !== null
		) {
			$this->keys[$node->keyName] = null;
		}

		if ($node instanceof IdentifierTypeNode && !isset($this->keys[$node])) {
			$canonical = $this->findCanonical($node->name);
			if ($this->canonical && $canonical !== null && $node->name !== $canonical) {
				$this->messages[] = ["The type `$node->name` in a doc comment must be written `$canonical`.", 'phpdoc.types.builtin'];
				$node->name = $canonical;
			}

		} elseif ($node instanceof ArrayTypeNode && $this->arrayNotation === 'generic') {
			$this->messages[] = ['An array type in a doc comment must be written `array<T>`.', 'phpdoc.types.array'];
			return new GenericTypeNode(new IdentifierTypeNode('array'), [$node->type]);

		} elseif (
			$node instanceof GenericTypeNode
			&& $this->arrayNotation === 'brackets'
			&& strtolower($node->type->name) === 'array'
			&& count($node->genericTypes) === 1
			&& (
				$node->genericTypes[0] instanceof IdentifierTypeNode
				|| $node->genericTypes[0] instanceof ArrayTypeNode
				|| $node->genericTypes[0] instanceof GenericTypeNode
			)
		) {
			$this->messages[] = ['An array type in a doc comment must be written `T[]`.', 'phpdoc.types.array'];
			return new ArrayTypeNode($node->genericTypes[0]);

		} elseif ($node instanceof UnionTypeNode) {
			return $this->rewriteUnion($node);
		}

		return null;
	}


	/**
	 * The union written the way the decisions ask, a new node where anything changed: the format-preserving
	 * printer prints a reordered list wrongly.
	 */
	private function rewriteUnion(UnionTypeNode $node): ?TypeNode
	{
		$unique = [];
		foreach ($node->types as $i => $type) {
			$unique[$this->canonical ? $this->readCanonically($type) : $i] ??= $type;
		}

		if (count($unique) < count($node->types)) {
			$this->messages[] = ['A union type in a doc comment names a type twice.', 'phpdoc.types.builtin'];
		}

		$nulls = $others = [];
		foreach ($unique as $type) {
			if ($type instanceof IdentifierTypeNode && strtolower($type->name) === 'null') {
				$nulls[] = $type;
			} else {
				$others[] = $type;
			}
		}

		if ($nulls && count($others) === 1 && $this->shortNullable && self::canBeNullable($others[0])) {
			$this->messages[] = ['A nullable type in a doc comment must be written `?T`.', 'phpdoc.types.nullable'];
			return new NullableTypeNode($others[0]);
		}

		if ($this->byName) {
			$sorted = $others;
			usort($sorted, fn(TypeNode $a, TypeNode $b) => strcasecmp(ltrim((string) $a, '('), ltrim((string) $b, '('))); // (A&B) sorts by A
			if ($sorted !== $others) {
				$this->messages[] = ['The types of a union type in a doc comment must be in alphabetical order.', 'phpdoc.types.unionOrder'];
				$others = $sorted;
			}
		}

		// the others in their new order, `null` where it stood
		$kept = [];
		$i = 0;
		foreach ($unique as $type) {
			$kept[] = in_array($type, $nulls, true) ? $type : $others[$i++];
		}

		$types = $kept;
		if ($this->nullPosition !== null && $nulls && $others) {
			$types = $this->nullPosition === 'first' ? [...$nulls, ...$others] : [...$others, ...$nulls];
			if ($types !== $kept) {
				$this->messages[] = ["`null` must come {$this->nullPosition} in a union type in a doc comment.", 'phpdoc.types.nullPosition'];
			}
		}

		return match (true) {
			$types === $node->types => null,
			count($types) === 1 => $types[0],
			default => new UnionTypeNode($types),
		};
	}


	/**
	 * The type as it reads with its name written canonically, which a union is deduplicated by before its items are
	 * visited, so that `integer|int` names one type.
	 */
	private function readCanonically(TypeNode $type): string
	{
		return $type instanceof IdentifierTypeNode
			? $this->findCanonical($type->name) ?? $type->name
			: (string) $type;
	}


	/** The canonical name of the built-in type the name stands for; null for a class. */
	private function findCanonical(string $name): ?string
	{
		$lower = strtolower($name);
		$canonical = self::Short[$lower] ?? (in_array($lower, self::Builtin, true) ? $lower : null);
		return $canonical === null || $canonical === $name || !($this->isClass)($name) ? $canonical : null;
	}


	/** Whether `?` may stand in front of the type, which has to be a single named or array type. */
	private static function canBeNullable(TypeNode $type): bool
	{
		return ($type instanceof IdentifierTypeNode && !in_array(strtolower($type->name), ['mixed', 'never', 'null', 'void'], true))
			|| $type instanceof GenericTypeNode
			|| $type instanceof ArrayTypeNode;
	}
}
