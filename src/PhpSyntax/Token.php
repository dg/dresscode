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


	public function __toString(): string
	{
		return implode('', array_map(fn(Trivia $trivia) => $trivia->text, $this->leadingTrivia))
			. $this->text
			. implode('', array_map(fn(Trivia $trivia) => $trivia->text, $this->trailingTrivia));
	}
}
