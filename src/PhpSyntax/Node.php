<?php declare(strict_types=1);

namespace PhpSyntax;

use PhpSyntax\Nodes\FileNode;


/**
 * Node of the concrete syntax tree; every token of the source is reachable through the children.
 */
abstract class Node implements \Stringable
{
	public ?Node $parent = null;


	/**
	 * Children in source order, without empty slots.
	 * @return list<Node|Token>
	 */
	abstract public function getChildren(): array;


	/**
	 * Replaces a direct child; the new one must fit the type of the slot.
	 */
	abstract public function replaceChild(self|Token $old, self|Token $new): void;


	/**
	 * Makes this node the parent of all its children.
	 * @internal called by the parser and the setters
	 */
	public function attach(): static
	{
		foreach ($this->getChildren() as $child) {
			$child->parent = $this;
		}

		return $this;
	}


	public function getFile(): ?FileNode
	{
		$node = $this;
		while ($node->parent) {
			$node = $node->parent;
		}

		return $node instanceof FileNode ? $node : null;
	}


	public function __toString(): string
	{
		return Printer::print($this);
	}


	/**
	 * Takes over a child that is not part of any tree yet.
	 */
	protected function adopt(self|Token $child): void
	{
		if ($child->parent) {
			throw new \LogicException('The node already belongs to a tree, clone it first.');
		}

		$child->parent = $this;
	}


	protected function release(self|Token|null $child): void
	{
		if ($child) {
			$child->parent = null;
		}
	}


	/**
	 * Tells the file that the children changed: every adopt() and release() since the last call is in place.
	 */
	protected function structureChanged(): void
	{
		$this->getFile()?->structureChanged();
	}


	/**
	 * Returns the list with $remove items at $index replaced by $insert.
	 * @template U
	 * @param  list<U>  $list
	 * @param  list<U>  $insert
	 * @return list<U>
	 */
	protected static function spliceList(array $list, int $index, int $remove, array $insert = []): array
	{
		return [...array_slice($list, 0, $index), ...$insert, ...array_slice($list, $index + $remove)];
	}


	protected static function describeSlotMismatch(string $slot, self|Token $child): \InvalidArgumentException
	{
		return new \InvalidArgumentException(
			($child instanceof Token ? "Token '$child->text'" : $child::class) . " cannot be placed in the slot '$slot' of " . static::class . '.',
		);
	}


	protected static function describeChildMismatch(self|Token $child): \InvalidArgumentException
	{
		return new \InvalidArgumentException(
			($child instanceof Token ? "Token '$child->text'" : $child::class) . ' is not a child of ' . static::class . '.',
		);
	}
}
