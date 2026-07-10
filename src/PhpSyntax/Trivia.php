<?php declare(strict_types=1);

namespace PhpSyntax;


/**
 * Whitespace, comment or open tag attached to a token; a value object that is never shared.
 */
final readonly class Trivia
{
	public function __construct(
		public TriviaKind $kind,
		public string $text,
		/** inside string interpolation, where whitespace is part of the string value */
		public bool $inInterpolation = false,
		/** line in the original file; null for trivia created by a mutation */
		public ?int $originalLine = null,
	) {
	}


	public function isComment(): bool
	{
		return $this->kind === TriviaKind::Comment || $this->kind === TriviaKind::DocComment;
	}


	/** Whitespace or a line ending. */
	public function isWhitespace(): bool
	{
		return $this->kind === TriviaKind::Whitespace || $this->kind === TriviaKind::EndOfLine;
	}


	/** Ends the line: a line ending, or an open tag whose text ends with one. */
	public function isEndOfLine(): bool
	{
		return $this->kind === TriviaKind::EndOfLine
			|| ($this->kind === TriviaKind::OpenTag && preg_match('~[\r\n]$~', $this->text) === 1);
	}
}
