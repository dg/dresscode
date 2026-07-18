<?php declare(strict_types=1);

namespace PhpSyntax;

use PhpSyntax\Nodes\SeparatedNodeList;
use function count;


/**
 * Changes of indentation that keep the shape of what they move: a token's line, the comments above it,
 * and the lines continuing a node shifted along with its first line.
 */
final class Indentation
{
	/**
	 * Whether the line of the token and the comments on their own lines above it already carry the indentation.
	 */
	public static function has(Token $token, string $indentation, string $commentIndentation): bool
	{
		return $token->getIndentation() === $indentation
			&& self::reindentComments($token->leadingTrivia, $commentIndentation) === null;
	}


	/**
	 * Sets the indentation of the line of the token (which must start a line) and of the comments above it;
	 * the lines continuing the node up to its last token shift by the same visual distance.
	 */
	public static function set(
		Token $token,
		string $indentation,
		string $commentIndentation,
		?Token $last,
		Style $style,
	): void
	{
		$current = $token->getIndentation();
		$leading = self::reindentComments($token->leadingTrivia, $commentIndentation);
		if ($leading !== null) {
			$token->setLeadingTrivia($leading);
		}

		$token->setIndentation($indentation);
		if ($last !== null && $current !== $indentation) {
			self::shift($token, $last, $current, $indentation, $style);
		}
	}


	/**
	 * Lines from the token after the first one up to the last: the old indentation at their start becomes
	 * the new one and what they had beyond it stays, in the characters of the style. Multi-line comments
	 * move with their lines.
	 */
	public static function shift(Token $first, Token $last, string $old, string $new, Style $style): void
	{
		if ($first === $last) {
			return;
		}

		for ($token = $first->getNext(); $token !== null; $token = $token->getNext()) {
			foreach ([true, false] as $isLeading) {
				$side = $isLeading ? $token->leadingTrivia : $token->trailingTrivia;
				$changed = false;
				foreach ($side as $i => $trivia) {
					if ($trivia->isComment()) {
						$reindented = self::reindentComment($trivia, $old, $new);
						if ($reindented !== $trivia) {
							$side[$i] = $reindented;
							$changed = true;
						}
					}
				}

				if ($changed) {
					$isLeading ? $token->setLeadingTrivia($side) : $token->setTrailingTrivia($side);
				}
			}

			$extra = $token->startsLine() ? self::width($token->getIndentation(), $style) - self::width($old, $style) : -1;
			if ($extra >= 0) {
				$token->setIndentation($new . self::normalize(str_repeat(' ', $extra), $style));
			}

			if ($token === $last) {
				break;
			}
		}
	}


	/**
	 * Puts every item of a delimited list on its own line, one unit deeper than the indentation given, and
	 * the closing delimiter on a line of its own at that indentation; an item spanning lines keeps its shape.
	 * @template T of Node
	 * @param SeparatedNodeList<T> $list
	 */
	public static function breakList(
		SeparatedNodeList $list,
		Token $open,
		Token $close,
		string $indentation,
		Style $style,
	): void
	{
		$eol = new Trivia(TriviaKind::EndOfLine, $style->eol);
		$inner = $indentation . $style->indent;
		$open->setTrailingTrivia(self::withoutSpace($open->trailingTrivia, $eol));
		$separators = $list->getSeparators();
		foreach ($list->getItems() as $i => $item) {
			$first = $item->getFirstToken();
			$last = $item->getLastToken();
			if ($first === null || $last === null) {
				continue;
			}

			$old = $first->startsLine() ? $first->getIndentation() : null;
			$first->setLeadingTrivia([...self::comments($first->leadingTrivia), new Trivia(TriviaKind::Whitespace, $inner)]);
			if ($old !== null && $old !== $inner) {
				self::shift($first, $last, $old, $inner, $style);
			}

			$end = $separators[$i] ?? $last;
			if ($end === $last && $i === count($list->getItems()) - 1 && !$list->hasTrailingSeparator()) {
				$end->setTrailingTrivia(self::withoutSpace($end->trailingTrivia, $eol));
			} elseif ($end !== $last) {
				$last->setTrailingTrivia(self::withoutSpace($last->trailingTrivia, null));
				$end->setTrailingTrivia(self::withoutSpace($end->trailingTrivia, $eol));
			}
		}

		$close->setLeadingTrivia([...self::comments($close->leadingTrivia), ...($indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)])]);
	}


	/**
	 * The trivia without whitespace and line endings, ending with the line ending given, comments kept.
	 * @param  list<Trivia>  $trivia
	 * @return list<Trivia>
	 */
	private static function withoutSpace(array $trivia, ?Trivia $eol): array
	{
		$result = [];
		foreach ($trivia as $item) {
			if ($item->isComment()) {
				$result[] = new Trivia(TriviaKind::Whitespace, ' ');
				$result[] = $item;
			}
		}

		if ($eol !== null) {
			$result[] = $eol;
		}

		return $result;
	}


	/**
	 * Comments among the trivia, each on a line of its own.
	 * @param  list<Trivia>  $trivia
	 * @return list<Trivia>
	 */
	private static function comments(array $trivia): array
	{
		$result = [];
		foreach ($trivia as $item) {
			if ($item->isComment()) {
				$result[] = $item;
				$result[] = new Trivia(TriviaKind::EndOfLine, "\n");
			}
		}

		return $result;
	}


	/**
	 * Visual width of an indentation, a tab counting as the tab width of the style.
	 */
	public static function width(string $indentation, Style $style): int
	{
		return strlen($indentation) + substr_count($indentation, "\t") * ($style->tabWidth - 1);
	}


	/**
	 * Indentation in the characters of the style: runs of spaces of the tab width become tabs, or tabs
	 * become the indentation unit.
	 */
	public static function normalize(string $indentation, Style $style): string
	{
		return $style->indent === "\t"
			? str_replace(str_repeat(' ', $style->tabWidth), "\t", $indentation)
			: str_replace("\t", $style->indent, $indentation);
	}


	/**
	 * Comments on their own lines among the trivia get the indentation; null when nothing changes.
	 * @param  list<Trivia>  $trivia
	 * @return ?list<Trivia>
	 */
	public static function reindentComments(array $trivia, string $indentation): ?array
	{
		$result = [];
		$changed = false;
		$count = count($trivia);
		for ($i = 0; $i < $count; $i++) {
			$item = $trivia[$i];
			$next = $trivia[$i + 1] ?? null;
			$atLineStart = $i === 0 ? $item->kind !== TriviaKind::OpenTag : $trivia[$i - 1]->isEndOfLine();
			if ($atLineStart && $item->isComment()) {
				$comment = self::reindentComment($item, '', $indentation);
				$changed = $changed || $comment !== $item || $indentation !== '';
				if ($indentation !== '') {
					$result[] = new Trivia(TriviaKind::Whitespace, $indentation);
				}

				$result[] = $comment;
			} elseif ($atLineStart && $item->kind === TriviaKind::Whitespace && $next?->isComment()) {
				$comment = self::reindentComment($next, $item->text, $indentation);
				$changed = $changed || $item->text !== $indentation || $comment !== $next;
				if ($indentation !== '') {
					$result[] = new Trivia(TriviaKind::Whitespace, $indentation);
				}

				$result[] = $comment;
				$i++;
			} else {
				$result[] = $item;
			}
		}

		return $changed ? $result : null;
	}


	/**
	 * A multi-line comment moves as a whole: its later lines lose the old indentation and get the new one.
	 */
	public static function reindentComment(Trivia $comment, string $old, string $new): Trivia
	{
		if (!str_contains($comment->text, "\n") || $old === $new) {
			return $comment;
		}

		$text = preg_replace('~(\r\n|\r|\n)' . preg_quote($old, '~') . '~', '$1' . $new, $comment->text);
		return $text === null || $text === $comment->text ? $comment : new Trivia($comment->kind, $text, $comment->inInterpolation);
	}
}
