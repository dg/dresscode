<?php declare(strict_types=1);

namespace DressCode\Rules\Comments;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * A space after the marker of a comment (`// foo`, `# foo`, `/* foo`) and before the closing one (`foo *\/`),
 * unless the marker is followed by another `/` or `*`, as in a `////` ruler; and whitespace before a comment
 * that follows code on its line. Doc comments are left to the phpDoc rules.
 */
#[RuleInfo(
	'dresscode/comment-spacing',
	Stage::Cleanup,
	description: 'Puts a space after the marker of a comment and before a comment following code',
	modifiesComments: true,
)]
final class CommentSpacingRule extends Rule implements ConfigurableRule
{
	private ?string $before = 'atLeastSingle';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'before' => Expect::anyOf('atLeastSingle', 'single')->default('atLeastSingle')->nullable()
				->description('Whitespace before a comment following code on its line: atLeastSingle keeps a wider gap such as an alignment, single collapses it to one space, null leaves it alone'),
		]);
	}


	public function configure(array $options): void
	{
		$this->before = $options['before'];
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ([true, false] as $isLeading) {
			$trivia = $isLeading ? $node->leadingTrivia : $node->trailingTrivia;
			$result = [];
			$changed = false;
			foreach ($trivia as $i => $item) {
				if ($item->kind === TriviaKind::Comment && !$item->inInterpolation) {
					$text = self::space($item->text);
					if (
						$text !== $item->text
						&& $context->report($node, 'A single space after the comment marker and before the closing one', trivia: $item)
					) {
						$item = new Trivia(TriviaKind::Comment, $text);
						$changed = true;
					}

					$previous = $result === [] ? null : $result[count($result) - 1];
					$gap = match (true) {
						$isLeading || $this->before === null || !self::endsLine($trivia, $i) => null,
						$previous?->kind !== TriviaKind::Whitespace => 'missing',
						$this->before === 'single' && $previous->text !== ' ' => 'wide',
						default => null,
					};
					if (
						$gap !== null
						&& $context->report($node, ($this->before === 'single' ? 'A single space' : 'At least one space') . ' before a comment following code', trivia: $item)
					) {
						$gap === 'missing' ? $result[] = new Trivia(TriviaKind::Whitespace, ' ') : $result[count($result) - 1] = new Trivia(TriviaKind::Whitespace, ' ');
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


	private static function space(string $text): string
	{
		if (str_starts_with($text, '/**')) {
			return $text; // shaped like a doc comment, even when the tokenizer does not take it for one
		}

		$text = (string) preg_replace('~^(//|#(?!\[)|/\*)(?![/*\s]|$)~', '$1 ', $text);
		return (string) preg_replace('~(?<![/*\s])(\*+/)$~', ' $1', $text);
	}


	/**
	 * Whether the comment is the last thing on its line: only whitespace follows it, with a line ending among it.
	 * @param list<Trivia> $trivia
	 */
	private static function endsLine(array $trivia, int $i): bool
	{
		$eol = false;
		foreach (array_slice($trivia, $i + 1) as $item) {
			if (!$item->isWhitespace()) {
				return false;
			}

			$eol = $eol || $item->isEndOfLine();
		}

		return $eol;
	}
}
