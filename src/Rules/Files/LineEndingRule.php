<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Token;
use PhpSyntax\Trivia;


/**
 * Every line ends the way the style says: the line endings of the file when the style follows the file,
 * otherwise the configured one. Line breaks inside strings, heredocs and inline HTML are content and stay.
 * The line ending is a property of the file, so the file is reported once, at the first line that has
 * the wrong one.
 */
#[RuleInfo(
	'dresscode/line-ending',
	Stage::Cleanup,
	description: 'Unifies line endings',
)]
final class LineEndingRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		$eol = $context->getStyle()->eol;
		$tokens = $context->getFile()->getTokens();
		foreach ($tokens as $token) {
			$trivia = self::findWrong($token->leadingTrivia, $eol) ?? self::findWrong($token->trailingTrivia, $eol);
			if ($trivia === null) {
				continue;
			}

			$name = $eol === "\r\n" ? 'CRLF' : 'LF';
			if ($context->report($token, "Wrong line endings, $name expected", trivia: $trivia)) {
				self::unifyAll($tokens, $eol);
			}

			return;
		}
	}


	/** @param  list<Token>  $tokens */
	private static function unifyAll(array $tokens, string $eol): void
	{
		foreach ($tokens as $token) {
			$leading = self::unify($token->leadingTrivia, $eol);
			if ($leading !== null) {
				$token->setLeadingTrivia($leading);
			}

			$trailing = self::unify($token->trailingTrivia, $eol);
			if ($trailing !== null) {
				$token->setTrailingTrivia($trailing);
			}
		}
	}


	/**
	 * @param  list<Trivia>  $trivia
	 * @return ?list<Trivia>  null when nothing changes
	 */
	private static function unify(array $trivia, string $eol): ?array
	{
		$changed = false;
		$result = [];
		foreach ($trivia as $item) {
			$rewritten = self::rewrite($item, $eol);
			$changed = $changed || $rewritten !== null;
			$result[] = $rewritten ?? $item;
		}

		return $changed ? $result : null;
	}


	/** @param  list<Trivia>  $trivia */
	private static function findWrong(array $trivia, string $eol): ?Trivia
	{
		foreach ($trivia as $item) {
			if (self::rewrite($item, $eol) !== null) {
				return $item;
			}
		}

		return null;
	}


	/** The trivia with the wanted line endings, or null when it has them already or must not be touched. */
	private static function rewrite(Trivia $trivia, string $eol): ?Trivia
	{
		if ($trivia->inInterpolation) {
			return null;
		}

		$text = (string) preg_replace('~\r\n|\r|\n~', $eol, $trivia->text);
		return $text === $trivia->text ? null : new Trivia($trivia->kind, $text, $trivia->inInterpolation);
	}
}
