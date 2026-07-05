<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TokenIndex;


/**
 * Root of the tree: the statements of a file and the end-of-file token carrying the trailing trivia.
 */
final class FileNode extends Node
{
	/** version of the tree: every write to a slot, a list, or the text or trivia of a token increments it */
	public int $revision = 0;

	private ?TokenIndex $index = null;


	/**
	 * @param NodeList<StatementNode> $stmts
	 * @internal
	 */
	public function __construct(
		public NodeList $stmts,
		public Token $eof,
	) {
	}


	/**
	 * A structural mutation is complete: the children reported by adopted() and released() are in their new
	 * places, and the index moves their tokens instead of rebuilding the order.
	 * @internal called by Node::structureChanged()
	 */
	public function structureChanged(): void
	{
		$this->revision++;
		$this->index?->updateStructure();
	}


	/**
	 * The text or trivia of a token changed: the lines after it move by the line endings it gained or lost,
	 * and with a change before the token so does its own.
	 * @internal called by the setters of Token
	 */
	public function tokenChanged(Token $token, int $lineEndings, bool $leading): void
	{
		$this->revision++;
		$this->index?->updateToken($token, $lineEndings, $leading);
	}


	/** @internal called by Node::adopt() */
	public function adopted(Node|Token $child): void
	{
		$this->index?->adopted($child);
	}


	/** @internal called by Node::release() while the child is still in the tree */
	public function released(Node|Token $child): void
	{
		$this->index?->released($child);
	}


	public function getIndex(): TokenIndex
	{
		return $this->index ??= new TokenIndex($this);
	}


	public function __clone()
	{
		parent::__clone();
		$this->index = null;
	}


	public function getChildren(): array
	{
		return [$this->stmts, $this->eof];
	}


	public function replaceChild(Node|Token $old, Node|Token $new): void
	{
		if ($old === $this->stmts && $new instanceof NodeList) {
			$this->setStmts($new);
		} elseif ($old === $this->eof && $new instanceof Token) {
			$this->setEof($new);
		} else {
			throw self::describeChildMismatch($old);
		}
	}


	/** @param NodeList<StatementNode> $stmts */
	public function setStmts(NodeList $stmts): void
	{
		$this->adopt($stmts);
		$this->release($this->stmts);
		$this->stmts = $stmts;
		$this->structureChanged();
	}


	public function setEof(Token $eof): void
	{
		$this->adopt($eof);
		$this->release($this->eof);
		$this->eof = $eof;
		$this->structureChanged();
	}
}
