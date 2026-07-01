<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Comments;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\{Node, Token, Trivia};
use function count;


/**
 * A space after the marker of a comment (`// foo`, `# foo`, `/* foo`) and before the closing one (`foo *\/`),
 * unless the marker is followed by another `/` or `*`, as in a `////` ruler; and whitespace before a comment
 * that follows code on its line. Doc comments are left to the phpDoc rules.
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true)]
final class CommentSpacingRule extends NodeRule
{
	private const Marker = 'spacing.comment';
	private const AfterCode = 'spacing.commentAfterCode';
	private const Alignment = 'spacing.commentAfterCodeAlignment';

	private bool $marker = true;

	private ?Space $before = Space::AtLeastOne;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Marker, new Shapes(['spaced' => ['// text', 'a single space after the marker']]), 'The space after the marker of a comment and before the closing one, unless another `/` or `*` follows the marker, as in a `////` ruler; doc comments are the matter of the phpDoc rules'),
			new Decision(self::AfterCode, new Shapes(['spaced' => ['$a; // text', 'at least one space']]), 'The whitespace before a comment that follows code on its line'),
			new Decision(self::Alignment, Domain::alignment('none', 'any'), 'Whether a comment following code may be aligned by more spaces', parameter: true, default: 'any'),
		];
	}


	public function configure(Values $values): void
	{
		$this->marker = !$values->isKept(self::Marker);
		$this->before = match (true) {
			$values->isKept(self::AfterCode) => null,
			$values->get(self::Alignment)->getWord() === 'none' => Space::Single,
			default => Space::AtLeastOne,
		};
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		foreach ([true, false] as $isLeading) {
			$trivia = $isLeading ? $node->leadingTrivia : $node->trailingTrivia;
			$result = [];
			$changed = false;
			foreach ($trivia as $i => $item) {
				if ($item->is(Trivia::Comment) && !$item->inInterpolation) {
					// shaped like a doc comment, even when the tokenizer does not take it for one
					$docShaped = str_starts_with($item->text, '/**');
					$opened = $docShaped ? $item->text : self::padOpeningMarker($item->text);
					$text = $docShaped || !str_starts_with($opened, '/*') ? $opened : self::padClosingMarker($opened);
					$message = match (true) {
						$opened === $item->text => 'Expected a single space before the closing comment marker.',
						$text === $opened => 'Expected a single space after the comment marker.',
						default => 'Expected a single space after the comment marker and before the closing one.',
					};
					if (
						$this->marker
						&& $text !== $item->text
						&& $context->report($node, $message, decision: self::Marker, trivia: $item)
					) {
						$item = $item->withText($text);
						$changed = true;
					}

					$previous = $result === [] ? null : $result[count($result) - 1];
					$gap = match (true) {
						$isLeading || $this->before === null || !self::endsLine($trivia, $i) => null,
						$previous === null => 'missing',
						$this->before === Space::Single && $previous->is(Trivia::Whitespace) && $previous->text !== ' ' => 'wide',
						default => null,
					};
					if (
						$gap !== null
						&& $context->report($node, ($this->before === Space::Single ? 'Expected a single space' : 'Expected at least one space') . ' before a comment following code.', decision: self::AfterCode, trivia: $item)
					) {
						$gap === 'missing' ? $result[] = Trivia::fromText(' ') : $result[count($result) - 1] = Trivia::fromText(' ');
						$changed = true;
					}
				}

				$result[] = $item;
			}

			if ($changed) {
				$isLeading ? $node->setLeadingTrivia($result) : $node->setTrailingTrivia($result);
			}
		}
	}


	private static function padOpeningMarker(string $text): string
	{
		return (string) preg_replace('~^(//|#(?!\[)|/\*)(?![/*\s]|$)~', '$1 ', $text);
	}


	private static function padClosingMarker(string $text): string
	{
		return (string) preg_replace('~(?<![/*\s])(\*+/)$~', ' $1', $text);
	}


	/**
	 * Whether no code follows the comment on its line: only whitespace and comments, with a line ending among them.
	 * @param  list<Trivia>  $trivia
	 */
	private static function endsLine(array $trivia, int $i): bool
	{
		$eol = false;
		foreach (array_slice($trivia, $i + 1) as $item) {
			if (!$item->isWhitespace() && !$item->isComment()) {
				return false;
			}

			$eol = $eol || $item->isLineEnding();
		}

		return $eol;
	}
}
