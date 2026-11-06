<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Trivia;
use function strlen;


/**
 * The text of the file is valid UTF-8. Only the first byte that is not is reported, in the token or the comment holding
 * it; it is never fixed, as the encoding the bytes were written in cannot be told from them.
 */
#[RuleInfo(Stage::Structure)]
final class NoInvalidUtf8Rule extends NodeRule
{
	/** The first byte of a text that is no part of a UTF-8 character, after the characters of RFC 3629 before it. */
	private const FirstInvalidByte = '#(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
		. '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+'
		. '\K[\x80-\xFF]#A';


	public static function getDecisions(): array
	{
		return [new Decision(
			'file.invalidUtf8',
			Domain::state('forbidden'),
			'A byte that is no part of a UTF-8 character, as a file in another encoding has; only reported',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		// the text of the file joined from its tokens, which printing the tree takes several times as long to give
		$tokens = $context->file->getTokens();
		$code = '';
		foreach ($tokens as $token) {
			foreach ($token->leadingTrivia as $trivia) {
				$code .= $trivia->text;
			}

			$code .= $token->text;
			foreach ($token->trailingTrivia as $trivia) {
				$code .= $trivia->text;
			}
		}

		if (preg_match('##u', $code) || !preg_match(self::FirstInvalidByte, $code, $m, PREG_OFFSET_CAPTURE)) {
			return;
		}

		[$byte, $offset] = $m[0];
		$message = sprintf('The file must be valid UTF-8, byte 0x%02X is not.', ord($byte));
		$end = 0;
		foreach ($tokens as $token) {
			foreach ([...$token->leadingTrivia, $token, ...$token->trailingTrivia] as $part) {
				$end += strlen($part->text);
				if ($end > $offset) {
					$context->report($token, $message, trivia: $part instanceof Trivia ? $part : null, fixable: false);
					return;
				}
			}
		}
	}
}
