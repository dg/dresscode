<?php declare(strict_types=1);

namespace PhpSyntax;

use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\ModifiersNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\SeparatedNodeList;


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


	/** Null only for a node without tokens, such as an empty list. */
	public function getFirstToken(): ?Token
	{
		foreach ($this->getChildren() as $child) {
			$token = $child instanceof Token ? $child : $child->getFirstToken();
			if ($token) {
				return $token;
			}
		}

		return null;
	}


	public function getLastToken(): ?Token
	{
		$children = $this->getChildren();
		for ($i = count($children) - 1; $i >= 0; $i--) {
			$token = $children[$i] instanceof Token ? $children[$i] : $children[$i]->getLastToken();
			if ($token) {
				return $token;
			}
		}

		return null;
	}


	/** Current line of the first token; null for a detached subtree or a node without tokens. */
	public function getStartLine(): ?int
	{
		return $this->getFirstToken()?->getLine();
	}


	/** Current line where the last token ends. */
	public function getEndLine(): ?int
	{
		$token = $this->getLastToken();
		$line = $token?->getLine();
		return $line === null ? null : $line + preg_match_all('~\r\n|\r|\n~', $token->text);
	}


	/**
	 * Doc comment before the node: the last one in the leading trivia of the first token, or in the trailing
	 * trivia of the previous token (public $a; /** @var int * / public $b;).
	 */
	public function getDocComment(): ?Trivia
	{
		return $this->locateDocComment()[1] ?? null;
	}


	/** Replaces the doc comment of the node (see getDocComment()) with the trivia given. */
	public function replaceDocComment(Trivia $docComment): void
	{
		[$owner, $old] = $this->locateDocComment() ?? throw new \LogicException('The node has no doc comment.');
		$owner->replaceTrivia($old, $docComment);
	}


	/** Removes the doc comment of the node (see getDocComment()) together with the line it stands on. */
	public function removeDocComment(): void
	{
		[$owner, $old] = $this->locateDocComment() ?? throw new \LogicException('The node has no doc comment.');
		$owner->removeTrivia($old);
	}


	/** @return ?array{Token, Trivia}  the token holding the doc comment among its trivia, and the doc comment */
	private function locateDocComment(): ?array
	{
		$token = $this->getFirstToken();
		if (!$token) {
			return null;
		}

		$previous = $token->getPrevious();
		foreach ([[$token, $token->leadingTrivia], [$previous, $previous->trailingTrivia ?? []]] as [$owner, $trivias]) {
			for ($i = count($trivias) - 1; $i >= 0; $i--) {
				if ($trivias[$i]->kind === TriviaKind::DocComment) {
					assert($owner !== null);
					return [$owner, $trivias[$i]];
				}
			}
		}

		return null;
	}


	/**
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return (T&Node)|null
	 */
	public function findAncestor(string $class): ?self
	{
		for ($node = $this->parent; $node; $node = $node->parent) {
			if ($node instanceof $class) {
				return $node;
			}
		}

		return null;
	}


	/**
	 * Descendant nodes in pre-order, as a snapshot safe to iterate while mutating the tree.
	 * @template T of Node
	 * @param  ?class-string<T>  $class
	 * @return list<($class is null ? Node : T)>
	 */
	public function getDescendants(?string $class = null): array
	{
		$result = [];
		$stack = array_reverse($this->getChildren());
		while ($stack) {
			$node = array_pop($stack);
			if ($node instanceof Token) {
				continue;
			}

			if ($class === null || $node instanceof $class) {
				$result[] = $node;
			}

			$children = $node->getChildren();
			for ($i = count($children) - 1; $i >= 0; $i--) {
				$stack[] = $children[$i];
			}
		}

		return $result;
	}


	/**
	 * Whether the tokens of both nodes carry the same texts, whatever the whitespace between them.
	 */
	public function matches(self $other): bool
	{
		return self::collectTexts($this) === self::collectTexts($other);
	}


	/** @return list<string> */
	private static function collectTexts(self|Token $item): array
	{
		if ($item instanceof Token) {
			return [$item->text];
		}

		$texts = [];
		foreach ($item->getChildren() as $child) {
			foreach (self::collectTexts($child) as $text) {
				$texts[] = $text;
			}
		}

		return $texts;
	}


	/**
	 * Whether a comment sits anywhere between the first and the last token of the node; the trivia
	 * on its outer edges do not count.
	 */
	public function hasComment(): bool
	{
		$first = $this->getFirstToken();
		$last = $this->getLastToken();
		return $first !== null && $last !== null && $first->hasCommentUpTo($last);
	}


	/**
	 * Whether reading the expression again gives the same value with no side effects: variables,
	 * property, constant and offset fetches and scalars, nothing that runs code. A magic getter
	 * behind a property fetch is out of sight and does not count.
	 */
	public function isRepeatableRead(): bool
	{
		foreach ([$this, ...$this->getDescendants()] as $node) {
			if (
				!$node instanceof Nodes\Expression\VariableNode
				&& !$node instanceof Nodes\Expression\ArrayDimFetchNode
				&& !$node instanceof Nodes\Expression\PropertyFetchNode
				&& !$node instanceof Nodes\Expression\StaticPropertyFetchNode
				&& !$node instanceof Nodes\Expression\ClassConstantFetchNode
				&& !$node instanceof Nodes\Expression\ConstantFetchNode
				&& !$node instanceof Nodes\NameNode
				&& !$node instanceof Nodes\IdentifierNode
				&& !$node instanceof Nodes\Scalar\IntegerNode
				&& !$node instanceof Nodes\Scalar\FloatNode
				&& !$node instanceof Nodes\Scalar\StringNode
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Replaces this node in its parent; the trivia around the old node stay in place around the new one.
	 */
	public function replaceWith(self $node): void
	{
		$parent = $this->parent ?? throw new \LogicException('Cannot replace a node without a parent.');
		if ($first = $this->getFirstToken()) {
			$leading = $first->leadingTrivia;
			$first->setLeadingTrivia([]);
			if ($target = $node->getFirstToken()) {
				$target->setLeadingTrivia([...$leading, ...$target->leadingTrivia]);
			}
		}

		if ($last = $this->getLastToken()) {
			$trailing = $last->trailingTrivia;
			$last->setTrailingTrivia([]);
			if ($target = $node->getLastToken()) {
				$target->setTrailingTrivia([...$target->trailingTrivia, ...$trailing]);
			}
		}

		$parent->replaceChild($this, $node);
	}


	/**
	 * Removes this node from its list. A node alone on its lines takes the lines with it (indentation and
	 * line ending), otherwise the whitespace around stays; comments inside, each with the line ending that
	 * follows it, go where the policy says.
	 */
	public function remove(CommentPolicy $comments = CommentPolicy::MoveToNextToken): void
	{
		$parent = $this->parent;
		if (!$parent instanceof NodeList && !$parent instanceof SeparatedNodeList) {
			throw new \LogicException('Only an item of a list can be removed; use the setter of the slot instead.');
		}

		$tokens = $this->getIndexedTokens();
		$first = $tokens[0] ?? null;
		$last = $tokens[count($tokens) - 1] ?? null;
		$previous = $first?->getPrevious();
		$next = $last?->getNext();
		[$leading, $moved] = self::splitComments($first->leadingTrivia ?? []);
		foreach ($tokens as $token) {
			foreach ([$token === $first ? [] : $token->leadingTrivia, $token === $last ? [] : $token->trailingTrivia] as $trivias) {
				$moved = [...$moved, ...self::splitComments($trivias)[1]];
			}
		}

		[$trailing, $trailingComments] = self::splitComments($last->trailingTrivia ?? []);
		$moved = [...$moved, ...$trailingComments];

		if (self::standsAlone($first->leadingTrivia ?? [], $last->trailingTrivia ?? [], $previous, $next)) {
			if ($leading && end($leading)->kind === TriviaKind::Whitespace) {
				array_pop($leading);
			}

			$trailing = [];
		}

		$before = $after = [];
		if ($comments === CommentPolicy::MoveToPreviousToken && $previous) {
			$before = $moved;
		} elseif ($comments !== CommentPolicy::Drop) {
			$after = $moved;
		}

		if ($previous) {
			$previous->setTrailingTrivia([...$previous->trailingTrivia, ...$before, ...$trailing]);
			$trailing = [];
		}

		if ($next) {
			$next->setLeadingTrivia([...$leading, ...$after, ...$trailing, ...$next->leadingTrivia]);
		} elseif ($previous) {
			$previous->setTrailingTrivia([...$previous->trailingTrivia, ...$leading, ...$after]);
		}

		$parent->removeItem($this);
	}


	public function __toString(): string
	{
		return Printer::print($this);
	}


	/** Deep copy without a parent. */
	public function __clone()
	{
		$this->parent = null;
		foreach (get_object_vars($this) as $name => $value) {
			if ($value instanceof self || $value instanceof Token) {
				$copy = clone $value;
				$copy->parent = $this;
				$this->$name = $copy;
			} elseif (
				is_array($value)
				&& (
					$this instanceof NodeList
					|| $this instanceof SeparatedNodeList
					|| $this instanceof ModifiersNode
				)
			) {
				$this->$name = array_map(function (Node|Token $item) {
					$copy = clone $item;
					$copy->parent = $this;
					return $copy;
				}, $value);
			}
		}
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
		$this->getFile()?->adopted($child);
	}


	protected function release(self|Token|null $child): void
	{
		if ($child) {
			$this->getFile()?->released($child);
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


	/** @return list<Token> */
	private function getIndexedTokens(): array
	{
		$tokens = [];
		$stack = [$this];
		while ($stack) {
			$node = array_pop($stack);
			if ($node instanceof Token) {
				$tokens[] = $node;
				continue;
			}

			$children = $node->getChildren();
			for ($i = count($children) - 1; $i >= 0; $i--) {
				$stack[] = $children[$i];
			}
		}

		return $tokens;
	}


	/**
	 * Separates comments, each with the line ending directly after it, from the rest of the trivia.
	 * @param  list<Trivia>  $trivias
	 * @return array{list<Trivia>, list<Trivia>}  [rest, comments]
	 */
	private static function splitComments(array $trivias): array
	{
		$rest = $comments = [];
		foreach ($trivias as $i => $trivia) {
			if ($trivia->isComment()) {
				$comments[] = $trivia;
			} elseif ($trivia->kind === TriviaKind::EndOfLine && $i > 0 && $trivias[$i - 1]->isComment()) {
				$comments[] = $trivia;
			} else {
				$rest[] = $trivia;
			}
		}

		return [$rest, $comments];
	}


	/**
	 * Whether nothing but whitespace and comments shares the lines of the node.
	 * @param list<Trivia> $leading
	 * @param list<Trivia> $trailing
	 */
	private static function standsAlone(array $leading, array $trailing, ?Token $previous, ?Token $next): bool
	{
		$startsLine = false;
		for ($i = count($leading) - 1; $i >= 0; $i--) {
			if ($leading[$i]->isEndOfLine()) {
				$startsLine = true;
				break;
			} elseif ($leading[$i]->kind !== TriviaKind::Whitespace && !$leading[$i]->isComment()) {
				return false;
			}
		}

		if (!$startsLine) {
			$before = $previous->trailingTrivia ?? [];
			$startsLine = !$previous || ($before && end($before)->isEndOfLine());
		}

		$endsLine = false;
		foreach ($trailing as $trivia) {
			if ($trivia->isEndOfLine()) {
				$endsLine = true;
				break;
			} elseif ($trivia->kind !== TriviaKind::Whitespace && !$trivia->isComment()) {
				return false;
			}
		}

		if (!$endsLine) {
			$after = $next->leadingTrivia ?? [];
			$endsLine = !$next || $next->kind === TokenKind::EndOfFile || ($after && $after[0]->isEndOfLine());
		}

		return $startsLine && $endsLine;
	}
}
