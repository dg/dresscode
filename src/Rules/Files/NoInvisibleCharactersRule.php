<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\HeredocNode;
use function in_array, strlen;


/**
 * No invisible characters in code: the no-break spaces, zero-width characters, line separators and bidi
 * controls that pasted text carries. In a comment they become a space or go away; in a string they become
 * a `\u{...}` escape, which keeps the value and shows the character, so a single-quoted string turns into
 * a double-quoted one; in a nowdoc, in a single-quoted string that double quotes would change, and in an
 * identifier or a variable name they are only reported.
 * Markup outside PHP tags is left alone.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true)]
final class NoInvisibleCharactersRule extends NodeRule
{
	/** character => its replacement in a comment */
	private const Characters = [
		"\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ',
		"\u{200B}" => '', "\u{2060}" => '', "\u{FEFF}" => '',
		"\u{2028}" => ' ', "\u{2029}" => ' ',
		"\u{202A}" => '', "\u{202B}" => '', "\u{202C}" => '', "\u{202D}" => '', "\u{202E}" => '',
		"\u{2066}" => '', "\u{2067}" => '', "\u{2068}" => '', "\u{2069}" => '',
	];


	public static function getDecisions(): array
	{
		return [new Decision('correctness.invisibleCharacters', Domain::state('forbidden'), 'Non-breaking, zero-width and bidi characters, replaced in comments and escaped in strings, which changes their text, and reported in names')];
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		if ($node->hasComment()) {
			foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
				if (
					$trivia->isComment()
					&& !$trivia->inInterpolation
					&& ($found = self::find($trivia->text)) !== null
					&& $context->report($node, "Invisible character $found in a comment.", trivia: $trivia)
				) {
					$node->replaceTrivia($trivia, $trivia->withText(strtr($trivia->text, self::Characters)));
				}
			}
		}

		$found = self::find($node->text);
		if ($found === null) {
			return;
		}

		if ($node->is(Token::ConstantEncapsedString)) {
			self::fixString($node, $found, $context);
		} elseif ($node->is(Token::EncapsedAndWhitespace)) {
			self::fixStringPart($node, $found, $context);
		} elseif ($node->is([
			Token::Variable, Token::Identifier, Token::StringVariableName, Token::NameQualified, Token::NameFullyQualified,
			Token::NameRelative,
		])) {
			$context->report($node, "Invisible character $found in a name.", fixable: false);
		}
	}


	/** A double-quoted string gets the escapes; a single-quoted one too, once its content is safe in double quotes. */
	private static function fixString(Token $token, string $found, RuleContext $context): void
	{
		$text = $token->text;
		$prefix = in_array($text[0], ['b', 'B'], true) ? $text[0] : '';
		$body = substr($text, strlen($prefix) + 1, -1);
		$convertible = $text[strlen($prefix)] === '"' || !preg_match('~[\\\$"{]~', $body);
		if (!$context->report($token, "Invisible character $found in a string.", fixable: $convertible) || !$convertible) {
			return;
		}

		$token->setText($prefix . '"' . self::escape($body) . '"');
	}


	/** A part of a heredoc or of a string with variables gets the escapes; one of a nowdoc keeps its text. */
	private static function fixStringPart(Token $token, string $found, RuleContext $context): void
	{
		$heredoc = $token->parent?->findAncestor(HeredocNode::class);
		$nowdoc = $heredoc !== null && str_contains($heredoc->openDelimiter->text, "'");
		if ($context->report($token, "Invisible character $found in a string.", fixable: !$nowdoc) && !$nowdoc) {
			$token->setText(self::escape($token->text));
		}
	}


	/** The characters as escapes; a lone backslash in front of one would escape the escape, so it gets a second one. */
	private static function escape(string $text): string
	{
		static $pattern = '~(\\\\*)(' . implode('|', array_map(fn(string $character) => preg_quote($character, '~'), array_keys(self::Characters))) . ')~';
		return preg_replace_callback(
			$pattern,
			fn(array $match) => $match[1] . (strlen($match[1]) % 2 === 1 ? '\\' : '') . sprintf('\u{%04X}', mb_ord($match[2], 'UTF-8')),
			$text,
		) ?? $text;
	}


	/** The code point of the first invisible character in the text, as U+XXXX; null when there is none. */
	private static function find(string $text): ?string
	{
		if (strpbrk($text, "\xC2\xE2\xEF") === false) { // every character of the list begins with one of these bytes in UTF-8
			return null;
		}

		$first = null;
		foreach (array_keys(self::Characters) as $character) {
			$position = strpos($text, $character);
			if ($position !== false && ($first === null || $position < $first[0])) {
				$first = [$position, $character];
			}
		}

		return $first === null ? null : sprintf('U+%04X', mb_ord($first[1], 'UTF-8'));
	}
}
