<?php declare(strict_types=1);

/**
 * Ported from nikic/php-parser (BSD-3-Clause, https://github.com/nikic/PHP-Parser).
 */

namespace PhpSyntax\Lexer\Emulators;

use PhpSyntax\Lexer\Emulator;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * __PROPERTY__ magic constant (PHP 8.4).
 */
final class PropertyToken implements Emulator
{
	public function isNeeded(string $code): bool
	{
		return stripos($code, '__property__') !== false;
	}


	public function emulate(array $tokens): array
	{
		foreach ($tokens as $i => $token) {
			if (
				$token->kind === TokenKind::Identifier
				&& strtolower($token->text) === '__property__'
				&& !self::isPropertyAccess($tokens, $i)
			) {
				$token->kind = TokenKind::MagicProperty;
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
