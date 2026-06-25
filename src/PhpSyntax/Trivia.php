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
	) {
	}
}
