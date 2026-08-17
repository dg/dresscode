<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\HeredocNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;


/**
 * No invisible characters in code: the no-break spaces, zero-width characters, line separators and bidi
 * controls that pasted text carries. In a comment they become a space or go away; in a string they become
 * a `\u{...}` escape, which keeps the value and shows the character, so a single-quoted string turns into
 * a double-quoted one when its content allows it; in an identifier or a variable name they are only reported.
 * Markup outside PHP tags is left alone.
 */
#[RuleInfo(
	'dresscode/no-invisible-characters',
	Stage::Structure,
	description: 'Removes or escapes invisible characters in comments and strings, reports them in names',
	modifiesComments: true,
)]
final class NoInvisibleCharactersRule extends Rule
{
	/** character → its replacement in a comment */
	private const Characters = [
		"\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ',
		"\u{200B}" => '', "\u{2060}" => '', "\u{FEFF}" => '',
		"\u{2028}" => ' ', "\u{2029}" => ' ',
		"\u{202A}" => '', "\u{202B}" => '', "\u{202C}" => '', "\u{202D}" => '', "\u{202E}" => '',
		"\u{2066}" => '', "\u{2067}" => '', "\u{2068}" => '', "\u{2069}" => '',
	];


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if (
				$trivia->isComment()
				&& !$trivia->inInterpolation
				&& ($found = self::find($trivia->text)) !== null
				&& $context->report($node, "Invisible character $found in a comment", trivia: $trivia)
			) {
				$node->replaceTrivia($trivia, new Trivia($trivia->kind, strtr($trivia->text, self::Characters)));
			}
		}

		$found = self::find($node->text);
		if ($found === null) {
			return;
		}

		switch ($node->kind) {
			case TokenKind::ConstantEncapsedString:
				$this->fixString($node, $found, $context);
				break;

			case TokenKind::EncapsedAndWhitespace:
				$heredoc = $node->parent?->findAncestor(HeredocNode::class);
				$nowdoc = $heredoc instanceof HeredocNode && str_contains($heredoc->openDelimiter->text, "'");
				if ($context->report($node, "Invisible character $found in a string") && !$nowdoc) {
					$node->setText(self::escape($node->text));
				}
				break;

			case TokenKind::Variable:
			case TokenKind::Identifier:
			case TokenKind::StringVarname:
			case TokenKind::NameQualified:
			case TokenKind::NameFullyQualified:
			case TokenKind::NameRelative:
				$context->report($node, "Invisible character $found in a name");
				break;
		}
	}


	/** A double-quoted string gets the escapes; a single-quoted one too, once its content is safe in double quotes. */
	private function fixString(Token $token, string $found, RuleContext $context): void
	{
		$text = $token->text;
		$convertible = $text[0] === '"' || !preg_match('~[\\\$"{]~', $text);
		if (!$context->report($token, "Invisible character $found in a string") || !$convertible) {
			return;
		}

		$token->setText('"' . self::escape(substr($text, 1, -1)) . '"');
	}


	private static function escape(string $text): string
	{
		$escapes = [];
		foreach (array_keys(self::Characters) as $character) {
			$escapes[$character] = sprintf('\u{%04X}', mb_ord($character, 'UTF-8'));
		}

		return strtr($text, $escapes);
	}


	/** The code point of the first invisible character in the text, as U+XXXX; null when there is none. */
	private static function find(string $text): ?string
	{
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
