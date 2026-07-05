<?php declare(strict_types=1);

namespace PhpSyntax;


final class Token implements \Stringable
{
	public ?Node $parent = null;

	/** @var list<Trivia> */
	public array $leadingTrivia = [];

	/** @var list<Trivia> */
	public array $trailingTrivia = [];


	public function __construct(
		public int $kind,
		public string $text,
		/** position in the original source; null for a token that did not come from the lexer */
		public readonly ?int $originalOffset = null,
		public readonly ?int $originalLine = null,
	) {
	}


	public function getFile(): ?Nodes\FileNode
	{
		return $this->parent?->getFile();
	}


	/** Navigation and positions come from the file index; a token of a detached subtree has none. */
	public function getNext(): ?self
	{
		return $this->getFile()?->getIndex()->getNext($this);
	}


	public function getPrevious(): ?self
	{
		return $this->getFile()?->getIndex()->getPrevious($this);
	}


	/** Current line, 1-based; unlike originalLine it follows mutations. */
	public function getLine(): ?int
	{
		return $this->getFile()?->getIndex()->getLine($this);
	}


	/** Current column, 1-based, in UTF-8 characters. */
	public function getColumn(): ?int
	{
		return $this->getFile()?->getIndex()->getColumn($this);
	}


	/** Current column with tabs expanded, 1-based. */
	public function getVisualColumn(Style $style): ?int
	{
		return $this->getFile()?->getIndex()->getVisualColumn($this, $style);
	}


	public function getOffset(): ?int
	{
		return $this->getFile()?->getIndex()->getOffset($this);
	}


	/** Copy without a parent; trivia are immutable and shared. */
	public function __clone()
	{
		$this->parent = null;
	}


	public function __toString(): string
	{
		return implode('', array_map(fn(Trivia $trivia) => $trivia->text, $this->leadingTrivia))
			. $this->text
			. implode('', array_map(fn(Trivia $trivia) => $trivia->text, $this->trailingTrivia));
	}
}
