<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * The file ends with exactly one line ending; a file ending with a close tag or inline HTML is left alone.
 */
#[RuleInfo(
	'dresscode/eof-newline',
	Stage::Cleanup,
	description: 'Ends the file with exactly one line ending',
)]
final class EofNewlineRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function afterFile(RuleContext $context): void
	{
		$eof = $context->getFile()->eof;
		$last = $eof->getPrevious();
		if ($last === null || $last->is(TokenKind::InlineHtml, TokenKind::CloseTag, TokenKind::HaltCompilerData)) {
			return;
		}

		$trailing = $last->trailingTrivia;
		$combined = [...$trailing, ...$eof->leadingTrivia];
		$keep = 0;
		foreach ($combined as $i => $trivia) {
			if ($trivia->isComment()) {
				$keep = $i + 1;
			}
		}

		$expected = [...array_slice($combined, 0, $keep), new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol)];
		if (self::print($combined) === self::print($expected)) {
			return;
		}

		if ($context->report($eof, 'The file must end with exactly one line ending')) {
			$split = min($keep, count($trailing));
			$last->setTrailingTrivia(array_slice($expected, 0, $keep < count($trailing) ? $keep + 1 : $split));
			$eof->setLeadingTrivia($keep < count($trailing) ? [] : array_slice($expected, $split));
		}
	}


	/** @param list<Trivia> $trivia */
	private static function print(array $trivia): string
	{
		return implode('', array_map(fn(Trivia $t) => $t->text, $trivia));
	}
}
