<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\Node;
use PhpSyntax\Token;
use function count;


/**
 * Sequence of nodes without separators: statements, members, attribute groups.
 * @template T of Node
 */
final class NodeList extends Node implements \Countable
{
	/**
	 * @param list<T> $items
	 * @internal
	 */
	public function __construct(
		public array $items = [],
	) {
	}


	/** @return list<T> */
	public function getItems(): array
	{
		return $this->items;
	}


	public function isEmpty(): bool
	{
		return $this->items === [];
	}


	/** @param T $item */
	public function append(Node $item): void
	{
		$this->adopt($item);
		$this->items[] = $item;
		$this->structureChanged();
	}


	/** @param T $item */
	public function insert(int $index, Node $item): void
	{
		$this->adopt($item);
		$this->items = self::spliceList($this->items, $index, 0, [$item]);
		$this->structureChanged();
	}


	public function removeItem(Node $item): void
	{
		$index = $this->indexOf($item);
		$this->release($item);
		$this->items = self::spliceList($this->items, $index, 1);
		$this->structureChanged();
	}


	public function indexOf(Node $item): int
	{
		$index = array_search($item, $this->items, strict: true);
		if ($index === false) {
			throw self::describeChildMismatch($item);
		}

		return $index;
	}


	public function getChildren(): array
	{
		return $this->items;
	}


	public function replaceChild(Node|Token $old, Node|Token $new): void
	{
		$index = $old instanceof Node ? $this->indexOf($old) : throw self::describeChildMismatch($old);
		if (!$new instanceof Node) {
			throw new \InvalidArgumentException('A token cannot be an item of ' . self::class . '.');
		}

		/** @var T $new  the item type is erased at runtime */
		$this->adopt($new);
		$this->release($old);
		$this->items = self::spliceList($this->items, $index, 1, [$new]);
		$this->structureChanged();
	}


	public function count(): int
	{
		return count($this->items);
	}
}
