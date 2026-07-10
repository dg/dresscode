<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * No whitespace at the end of a line, single-line comments included; the content of strings is left alone.
 */
#[RuleInfo(
	'dresscode/no-trailing-whitespace',
	Stage::Cleanup,
	description: 'Removes whitespace at the end of lines',
)]
final class NoTrailingWhitespaceRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		$leading = $this->clean($node, $node->leadingTrivia, $node->kind === TokenKind::EndOfFile, $context);
		if ($leading !== null) {
			$node->setLeadingTrivia($leading);
		}

		$trailing = $this->clean($node, $node->trailingTrivia, false, $context);
		if ($trailing !== null) {
			$node->setTrailingTrivia($trailing);
		}
	}


	/**
	 * The trivia without whitespace before a line ending (or at the end of the file) and without whitespace
	 * ending a single-line comment, each removal reported; null when nothing changes.
	 * @param  list<Trivia>  $trivia
	 * @return ?list<Trivia>
	 */
	private function clean(Token $token, array $trivia, bool $atEnd, RuleContext $context): ?array
	{
		$result = [];
		$changed = false;
		foreach ($trivia as $i => $item) {
			$next = $trivia[$i + 1] ?? null;
			$replacement = match (true) {
				$item->inInterpolation => $item,
				$item->kind === TriviaKind::Whitespace && ($next === null ? $atEnd : $next->kind === TriviaKind::EndOfLine) => null,
				($item->kind === TriviaKind::OpenTag && $next?->kind === TriviaKind::EndOfLine)
					|| ($item->kind === TriviaKind::Comment && !str_starts_with($item->text, '/*')) => self::trim($item),
				default => $item,
			};

			if ($replacement !== $item && !$context->report($token, 'Trailing whitespace', trivia: $item)) {
				$replacement = $item;
			}

			$changed = $changed || $replacement !== $item;
			if ($replacement !== null) {
				$result[] = $replacement;
			}
		}

		return $changed ? $result : null;
	}


	private static function trim(Trivia $trivia): Trivia
	{
		$trimmed = rtrim($trivia->text, " \t");
		return $trimmed === $trivia->text ? $trivia : new Trivia($trivia->kind, $trimmed, $trivia->inInterpolation);
	}
}
