<?php declare(strict_types=1);

namespace PhpSyntax;

use function count, ord;


final class Token implements \Stringable
{
	public ?Node $parent = null;

	/** @internal position in the order of the tokens of the file, written by TokenIndex */
	public int $index = 0;

	/** @internal the index that numbered the token last: a shortcut to the file, verified before use */
	public ?TokenIndex $indexedBy = null;

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


	public function is(int|string ...$kinds): bool
	{
		foreach ($kinds as $kind) {
			if (is_int($kind) ? $this->kind === $kind : $this->text === $kind) {
				return true;
			}
		}

		return false;
	}


	/** A semicolon or a close tag standing in for it. */
	public function isSemicolon(): bool
	{
		return $this->kind === ord(';') || $this->kind === TokenKind::CloseTag;
	}


	public function isOpenTagWithEcho(): bool
	{
		return $this->kind === TokenKind::OpenTagWithEcho;
	}


	public function getFile(): ?Nodes\FileNode
	{
		return $this->parent?->getFile();
	}


	/** Navigation and positions come from the file index; a token of a detached subtree has none. */
	public function getNext(): ?self
	{
		return $this->findIndex()?->getNext($this);
	}


	public function getPrevious(): ?self
	{
		return $this->findIndex()?->getPrevious($this);
	}


	/** Current line, 1-based; unlike originalLine it follows mutations. */
	public function getLine(): ?int
	{
		return $this->findIndex()?->getLine($this);
	}


	/** Current column, 1-based, in UTF-8 characters. */
	public function getColumn(): ?int
	{
		return $this->findIndex()?->getColumn($this);
	}


	/** Current column with tabs expanded, 1-based. */
	public function getVisualColumn(Style $style): ?int
	{
		return $this->findIndex()?->getVisualColumn($this, $style);
	}


	public function getOffset(): ?int
	{
		return $this->findIndex()?->getOffset($this);
	}


	/** The index of the file the token is in: the one that numbered it when it still holds it, else via the parents. */
	private function findIndex(): ?TokenIndex
	{
		$index = $this->indexedBy;
		return $index !== null && $index->contains($this)
			? $index
			: $this->getFile()?->getIndex();
	}


	/** Whether the token is the first on its line. */
	public function startsLine(): bool
	{
		foreach ($this->leadingTrivia as $trivia) {
			if ($trivia->isEndOfLine()) {
				return true;
			}
		}

		$previous = $this->getPrevious();
		$before = $previous?->trailingTrivia;
		return $previous === null
			|| ($before ? end($before)->isEndOfLine() : preg_match('~[\r\n]$~', $previous->text) === 1);
	}


	/** Indentation of the line the token is on, whether the token starts it or not. */
	public function getLineIndentation(): string
	{
		$token = $this;
		while (!$token->startsLine()) {
			$token = $token->getPrevious() ?? throw new \LogicException('A token without a file has no line.');
		}

		return $token->getIndentation();
	}


	/**
	 * Whitespace at the start of the token's line, before any comment sitting between it and the token;
	 * empty when the token does not start a line.
	 */
	public function getIndentation(): string
	{
		$indentation = '';
		foreach (array_slice($this->leadingTrivia, $this->findLineStart()) as $trivia) {
			if ($trivia->kind !== TriviaKind::Whitespace) {
				break;
			}

			$indentation .= $trivia->text;
		}

		return $indentation;
	}


	/** Index of the first trivia after the last line ending or open tag. */
	private function findLineStart(): int
	{
		$start = 0;
		foreach ($this->leadingTrivia as $i => $trivia) {
			if ($trivia->isEndOfLine() || $trivia->kind === TriviaKind::OpenTag) {
				$start = $i + 1;
			}
		}

		return $start;
	}


	/** The text and the trivia are written only through the setters, which keep the index of the file up to date. */
	public function setText(string $text): void
	{
		$file = $this->getFile();
		$lineEndings = $file ? TokenIndex::countLineEndings($text) - TokenIndex::countLineEndings($this->text) : 0;
		$this->text = $text;
		$file?->tokenChanged($this, $lineEndings, leading: false);
	}


	/** @param list<Trivia> $trivia */
	public function setLeadingTrivia(array $trivia): void
	{
		$file = $this->getFile();
		$lineEndings = $file ? TokenIndex::countLineEndingsIn($trivia) - TokenIndex::countLineEndingsIn($this->leadingTrivia) : 0;
		$this->leadingTrivia = $trivia;
		$file?->tokenChanged($this, $lineEndings, leading: true);
	}


	/** @param list<Trivia> $trivia */
	public function setTrailingTrivia(array $trivia): void
	{
		$file = $this->getFile();
		$lineEndings = $file ? TokenIndex::countLineEndingsIn($trivia) - TokenIndex::countLineEndingsIn($this->trailingTrivia) : 0;
		$this->trailingTrivia = $trivia;
		$file?->tokenChanged($this, $lineEndings, leading: false);
	}


	/**
	 * Replaces the whitespace at the start of the token's line, leaving a comment sitting between it
	 * and the token alone; the token must start a line.
	 */
	public function setIndentation(string $indentation): void
	{
		$this->refuseInterpolation();
		if (!$this->startsLine()) {
			throw new \LogicException("Token '$this->text' does not start a line.");
		}

		$leading = $this->leadingTrivia;
		$start = $this->findLineStart();
		$end = $start;
		while (($leading[$end] ?? null)?->kind === TriviaKind::Whitespace) {
			$end++;
		}

		$replacement = $indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)];
		$this->setLeadingTrivia([...array_slice($leading, 0, $start), ...$replacement, ...array_slice($leading, $end)]);
	}


	/**
	 * Moves the token to its own line unless it already starts one; the indentation stays as it was.
	 * The line ending goes to the trailing trivia of the previous token, where the lexer would put it.
	 */
	public function ensureLeadingNewline(string $eol = "\n"): void
	{
		$this->refuseInterpolation();
		if ($this->startsLine()) {
			return;
		}

		$previous = $this->getPrevious();
		if ($previous === null) {
			$this->setLeadingTrivia([new Trivia(TriviaKind::EndOfLine, $eol), ...$this->leadingTrivia]);
			return;
		}

		$previous->removeTrailingWhitespace();
		$previous->setTrailingTrivia([...$previous->trailingTrivia, new Trivia(TriviaKind::EndOfLine, $eol)]);
	}


	/**
	 * Removes whitespace at the end of the line the token ends, keeping comments and the line ending;
	 * whitespace ending a single-line comment counts as well, since the tokenizer makes it part of the comment.
	 */
	public function removeTrailingWhitespace(): void
	{
		$this->refuseInterpolation();
		$trailing = [];
		$whitespace = [];
		foreach ($this->trailingTrivia as $trivia) {
			if ($trivia->kind === TriviaKind::Whitespace) {
				$whitespace[] = $trivia;
			} elseif ($trivia->kind === TriviaKind::EndOfLine) {
				$trailing[] = $trivia;
				$whitespace = [];
			} else {
				$trailing = [...$trailing, ...$whitespace, $trivia];
				$whitespace = [];
			}
		}

		$last = $trailing ? $trailing[count($trailing) - 1] : null;
		$comment = $last?->kind === TriviaKind::Comment && !str_starts_with($last->text, '/*')
			? $last
			: ($trailing[count($trailing) - 2] ?? null);
		if (
			$comment?->kind === TriviaKind::Comment
			&& !str_starts_with($comment->text, '/*')
			&& rtrim($comment->text) !== $comment->text
		) {
			$trimmed = new Trivia(TriviaKind::Comment, rtrim($comment->text), $comment->inInterpolation);
			$trailing = array_map(fn(Trivia $trivia) => $trivia === $comment ? $trimmed : $trivia, $trailing);
		}

		$this->setTrailingTrivia($trailing);
	}


	/** Whether a comment sits in the leading or trailing trivia of the token. */
	public function hasComment(): bool
	{
		foreach ([...$this->leadingTrivia, ...$this->trailingTrivia] as $trivia) {
			if ($trivia->isComment()) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Whether a comment sits anywhere between the text of this token and the text of the given one:
	 * in the trailing trivia here, the leading trivia there, or around any token between them.
	 */
	public function hasCommentUpTo(self $end): bool
	{
		for ($token = $this; $token !== null; $token = $token->getNext()) {
			foreach ($token === $this ? [] : $token->leadingTrivia as $trivia) {
				if ($trivia->isComment()) {
					return true;
				}
			}

			if ($token === $end) {
				return false;
			}

			foreach ($token->trailingTrivia as $trivia) {
				if ($trivia->isComment()) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Sets the number of blank lines before the token, which must start a line; comments before it keep
	 * their position after the blank lines.
	 */
	public function setBlankLinesBefore(int $count, string $eol = "\n"): void
	{
		$this->refuseInterpolation();
		if (!$this->startsLine()) {
			throw new \LogicException("Token '$this->text' does not start a line.");
		}

		$leading = $this->leadingTrivia;
		$start = $leading && $leading[0]->kind === TriviaKind::OpenTag ? 1 : 0;
		$end = $start;
		while ($end < count($leading) && $leading[$end]->kind === TriviaKind::EndOfLine) {
			$end++;
		}

		$blank = array_fill(0, $count, new Trivia(TriviaKind::EndOfLine, $eol));
		$this->setLeadingTrivia([...array_slice($leading, 0, $start), ...$blank, ...array_slice($leading, $end)]);
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


	/** Whitespace inside string interpolation is part of the string value and must not be reformatted. */
	private function refuseInterpolation(): void
	{
		foreach ([...$this->leadingTrivia, ...$this->trailingTrivia] as $trivia) {
			if ($trivia->inInterpolation) {
				throw new \LogicException("Token '$this->text' is inside string interpolation; its whitespace cannot be changed.");
			}
		}
	}
}
