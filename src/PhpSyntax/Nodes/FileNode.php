<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\Node;
use PhpSyntax\Token;


/**
 * Root of the tree: the statements of a file and the end-of-file token carrying the trailing trivia.
 */
final class FileNode extends Node
{
	/** number of mutations since parsing; every mutating method increments it */
	public int $revision = 0;


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
	 * Records a structural mutation: the children of a node were replaced, added or removed.
	 * @internal
	 */
	public function structureChanged(): void
	{
		$this->revision++;
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
