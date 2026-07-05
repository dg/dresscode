<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * Sequence of nodes with separator tokens between them and an optional trailing separator:
 * parameters, arguments, array items, imports. An item may be an empty node standing for
 * nothing between two separators ([, $b] = $x).
 * @template T of Node
 */
final class SeparatedNodeList extends Node implements \Countable
{
	/**
	 * @param list<T> $items
	 * @param list<Token> $separators  one before each item but the first, plus an optional trailing one
	 * @internal
	 */
	public function __construct(
		public array $items = [],
		public array $separators = [],
	) {
	}


	/** @return list<T> */
	public function getItems(): array
	{
		return $this->items;
	}


	/** @return list<Token> */
	public function getSeparators(): array
	{
		return $this->separators;
	}


	public function isEmpty(): bool
	{
		return $this->items === [];
	}


	public function hasTrailingSeparator(): bool
	{
		return count($this->separators) === count($this->items) && $this->items !== [];
	}


	/**
	 * Appends an item; the separator before it is derived from the existing ones unless given.
	 * @param T $item
	 */
	public function append(Node $item, ?Token $separator = null): void
	{
		$this->insert(count($this->items), $item, $separator);
	}


	/**
	 * Inserts an item at the index. A missing separator is modeled on the existing ones, or on ", " in
	 * a one-line list; in a multi-line list the item also inherits the indentation of its neighbor.
	 * @param T $item
	 */
	public function insert(int $index, Node $item, ?Token $separator = null): void
	{
		if ($index < 0 || $index > count($this->items)) {
			throw new \OutOfRangeException("Index $index is out of range.");
		}

		if ($this->items === []) {
			if ($separator) {
				throw new \LogicException('The first item has no separator before it.');
			}
		} elseif (!$separator) {
			$neighbor = $this->items[$index > 0 ? $index - 1 : 0];
			$separator = $this->deriveSeparator($index, $neighbor);
			$this->indentLike($item, $neighbor);
			if ($index > 0) {
				$this->endLineLike($item, $neighbor);
			}
		}

		$this->adopt($item);
		$this->items = self::spliceList($this->items, $index, 0, [$item]);
		if ($separator) {
			$this->adopt($separator);
			$this->separators = self::spliceList($this->separators, max($index - 1, 0), 0, [$separator]);
		}

		$this->structureChanged();
	}


	public function setTrailingSeparator(?Token $separator): void
	{
		if ($this->hasTrailingSeparator()) {
			$this->release(array_pop($this->separators));
		}

		if ($separator) {
			$this->adopt($separator);
			$this->separators[] = $separator;
		}

		$this->structureChanged();
	}


	/**
	 * Removes the item together with the separator after it, or the one before it for the last item.
	 */
	public function removeItem(Node $item): void
	{
		$index = $this->indexOf($item);
		$isLast = $index === count($this->items) - 1;
		$this->release($item);
		$this->items = self::spliceList($this->items, $index, 1);
		if ($this->items === []) {
			array_walk($this->separators, $this->release(...));
			$this->separators = [];
		} elseif (isset($this->separators[$separatorIndex = $isLast ? $index - 1 : $index])) {
			$this->release($this->separators[$separatorIndex]);
			$this->separators = self::spliceList($this->separators, $separatorIndex, 1);
		}

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
		$children = [];
		foreach ($this->items as $index => $item) {
			if ($index > 0) {
				$children[] = $this->separators[$index - 1];
			}

			$children[] = $item;
		}

		if ($this->hasTrailingSeparator()) {
			$children[] = $this->separators[count($this->items) - 1];
		}

		return $children;
	}


	public function replaceChild(Node|Token $old, Node|Token $new): void
	{
		if ($old instanceof Node && $new instanceof Node) {
			$index = $this->indexOf($old);
			/** @var T $new  the item type is erased at runtime */
			$this->adopt($new);
			$this->release($old);
			$this->items = self::spliceList($this->items, $index, 1, [$new]);

		} elseif ($old instanceof Token && $new instanceof Token) {
			$index = array_search($old, $this->separators, strict: true);
			if ($index === false) {
				throw self::describeChildMismatch($old);
			}

			$this->adopt($new);
			$this->release($old);
			$this->separators = self::spliceList($this->separators, $index, 1, [$new]);

		} else {
			throw new \InvalidArgumentException('An item can be replaced only by a node and a separator only by a token.');
		}

		$this->structureChanged();
	}


	public function count(): int
	{
		return count($this->items);
	}


	private function deriveSeparator(int $index, Node $neighbor): Token
	{
		$model = $this->separators[min($index, count($this->separators)) - 1] ?? $this->separators[0] ?? null;
		if ($model) {
			return clone $model;
		}

		$separator = new Token(ord(','), ',');
		$eol = self::findLineEnding($neighbor);
		$separator->setTrailingTrivia([$eol ?? new Trivia(TriviaKind::Whitespace, ' ')]);
		return $separator;
	}


	/**
	 * When the neighbor ends its line, the new item after it takes over that role.
	 */
	private function endLineLike(Node $item, Node $neighbor): void
	{
		$source = $neighbor->getLastToken();
		$target = $item->getLastToken();
		if (
			$source
			&& $target
			&& $source->trailingTrivia
			&& end($source->trailingTrivia)->isEndOfLine()
			&& !$target->trailingTrivia
		) {
			$target->setTrailingTrivia($source->trailingTrivia);
			$source->setTrailingTrivia([]);
		}
	}


	/** The line ending before the item when it starts a line. */
	private static function findLineEnding(Node $item): ?Trivia
	{
		$token = $item->getFirstToken();
		if (!$token) {
			return null;
		}

		foreach ([array_reverse($token->leadingTrivia), array_reverse($token->getPrevious()->trailingTrivia ?? [])] as $trivias) {
			foreach ($trivias as $trivia) {
				if ($trivia->kind === TriviaKind::EndOfLine) {
					return $trivia;
				} elseif ($trivia->kind !== TriviaKind::Whitespace) {
					return null;
				}
			}
		}

		return null;
	}


	/**
	 * Gives the item the leading indentation of its neighbor when it has no leading trivia of its own
	 * and the neighbor starts a line.
	 */
	private function indentLike(Node $item, Node $neighbor): void
	{
		$target = $item->getFirstToken();
		$source = $neighbor->getFirstToken();
		if (!$target || !$source || $target->leadingTrivia) {
			return;
		}

		$indentation = [];
		foreach ($source->leadingTrivia as $trivia) {
			$indentation = $trivia->kind === TriviaKind::Whitespace ? [...$indentation, $trivia] : [];
		}

		if ($source->startsLine()) {
			$target->setLeadingTrivia($indentation);
		}
	}
}
