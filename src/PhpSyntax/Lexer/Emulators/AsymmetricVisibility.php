<?php declare(strict_types=1);

/**
 * Ported from nikic/php-parser (BSD-3-Clause, https://github.com/nikic/PHP-Parser).
 */

namespace PhpSyntax\Lexer\Emulators;

use PhpSyntax\Lexer\Emulator;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count, ord;


/**
 * public(set), protected(set) and private(set) modifiers (PHP 8.4).
 */
final class AsymmetricVisibility implements Emulator
{
	private const Kinds = [
		TokenKind::Public => TokenKind::PublicSet,
		TokenKind::Protected => TokenKind::ProtectedSet,
		TokenKind::Private => TokenKind::PrivateSet,
	];


	public function isNeeded(string $code): bool
	{
		return preg_match('~(public|protected|private)\(set\)~i', $code) === 1;
	}


	public function emulate(array $tokens): array
	{
		for ($i = 0, $count = count($tokens); $i < $count; $i++) {
			$token = $tokens[$i];
			if (
				isset(self::Kinds[$token->kind])
				&& $i + 3 < $count
				&& $tokens[$i + 1]->kind === ord('(')
				&& $tokens[$i + 2]->kind === TokenKind::Identifier
				&& strtolower($tokens[$i + 2]->text) === 'set'
				&& $tokens[$i + 3]->kind === ord(')')
				&& !self::isPropertyAccess($tokens, $i)
			) {
				$merged = new Token(
					self::Kinds[$token->kind],
					$token->text . '(' . $tokens[$i + 2]->text . ')',
					$token->originalOffset,
					$token->originalLine,
				);
				array_splice($tokens, $i, 4, [$merged]);
				$count -= 3;
			}
		}

		return $tokens;
	}


	/** @param list<Token> $tokens */
	private static function isPropertyAccess(array $tokens, int $index): bool
	{
		while (--$index >= 0) {
			$kind = $tokens[$index]->kind;
			if ($kind !== TokenKind::Whitespace && $kind !== TokenKind::Comment && $kind !== TokenKind::DocComment) {
				return $kind === TokenKind::ObjectOperator || $kind === TokenKind::NullsafeObjectOperator;
			}
		}

		return false;
	}
}
