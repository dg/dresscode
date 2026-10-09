<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Token;


/**
 * Whitespace around a semicolon: none before it, which keeps it on the line of what it ends, a single space after
 * it when more follows on the line, as in the head of a `for` loop; `for (;;)` stays. Where
 * `multiline.semicolonOnOwnLine` is `keep`, a semicolon may take a line of its own below a statement spanning several
 * lines.
 */
#[RuleInfo(Stage::Formatting)]
final class SemicolonSpacingRule extends GapRule
{
	private const Before = 'spacing.semicolon.before';
	private const After = 'spacing.semicolon.after';
	private const OwnLine = 'multiline.semicolonOnOwnLine';

	private bool $before = true;
	private bool $after = true;
	private bool $allowOwnLine = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Before, new Shapes(['compact' => ['$a;', 'no space before a semicolon']]), 'The whitespace before a semicolon, which stays on the line of what it ends'),
			new Decision(self::After, new Shapes(['spaced' => ['for ($i = 0; $i < 5; $i++)', 'a single space after a semicolon']]), 'The whitespace after a semicolon that more code follows on its line'),
			new Decision(self::OwnLine, Domain::state('forbidden'), 'A semicolon standing on a line of its own below a statement spanning several lines, instead of closing its last line'),
		];
	}


	public function configure(Values $values): void
	{
		$this->before = !$values->isKept(self::Before);
		$this->after = !$values->isKept(self::After);
		$this->allowOwnLine = $values->isKept(self::OwnLine);
	}


	public function getClaims(): array
	{
		$claim = [
			$this->before || !$this->allowOwnLine ? $this->claimBefore(...) : null,
			$this->after ? self::claimAfter(...) : null,
		];
		return ['*' => ['semicolon' => $claim, 'firstSemicolon' => $claim, 'secondSemicolon' => $claim, 'leadingSemicolon' => $claim]];
	}


	/**
	 * A close tag ending a statement, as in a template's `echo`, is no semicolon to write tight; a semicolon below the end
	 * of a heredoc, where PHP before 7.3 wanted it, keeps its line, and so does one below a statement spanning several
	 * lines where it may.
	 */
	private function claimBefore(Gap $gap): ?Claim
	{
		static $hug = new Claim(Space::None, line: Line::Same, decision: self::Before);
		static $hugBelow = new Claim(Space::None, line: Line::Same, decision: self::Before, decisionLine: self::OwnLine);
		static $sameLine = new Claim(line: Line::Same, decision: self::OwnLine);
		static $noSpace = new Claim(Space::None, decision: self::Before);
		$token = $gap->token;
		$previous = $token->getPrevious();
		$first = $token->parent?->getFirstToken();
		$below = $previous !== null && $first !== null && $first !== $token && $first->getCurrentLine() !== $previous->getCurrentLine();
		return match (true) {
			$token->is(Token::CloseTag) => null,
			$previous?->is(Token::EndHeredoc) ?? false => $this->before ? $noSpace : null,
			$below && $this->allowOwnLine => $noSpace,
			$below => $this->before ? $hugBelow : $sameLine,
			$this->before => $hug,
			default => null,
		};
	}


	/** Nothing follows the `;` of `for (;;)`, and what follows the one of `__halt_compiler()` or stands before `?>` is not code. */
	private static function claimAfter(Gap $gap): ?Claim
	{
		static $single = new Claim(Space::Single, decision: self::After);
		$token = $gap->token;
		$next = $token->getNext();
		return $token->is(Token::CloseTag) || $next === null || $next->is([';', ')', Token::CloseTag, Token::HaltCompilerData])
			? null
			: $single;
	}
}
