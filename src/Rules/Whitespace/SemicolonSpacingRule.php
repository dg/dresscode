<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\TokenKind;


/**
 * Whitespace around a semicolon: none before it, which keeps it on the line of what it ends, a single space after
 * it when more follows on the line, as in the head of a for loop; `for (;;)` stays. By the option a semicolon may take
 * a line of its own below a statement spanning several lines. Either side can be left alone by keep.
 */
#[RuleInfo(
	'dresscode/semicolon-spacing',
	Stage::Formatting,
	description: 'Removes whitespace before a semicolon and puts a single space after it',
)]
final class SemicolonSpacingRule extends GapRule implements ConfigurableRule
{
	private ?string $before = 'none';
	private ?string $after = 'single';
	private bool $allowOwnLine = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'before' => Expect::anyOf('none', 'keep')->default('none'),
			'after' => Expect::anyOf('single', 'keep')->default('single'),
			'allowOwnLine' => Expect::bool(false)
				->description('A semicolon may stand on a line of its own below a statement spanning several lines'),
		]);
	}


	public function configure(array $options): void
	{
		$this->before = $options['before'] === 'keep' ? null : $options['before'];
		$this->after = $options['after'] === 'keep' ? null : $options['after'];
		$this->allowOwnLine = $options['allowOwnLine'];
	}


	public function getClaims(): array
	{
		$claim = [$this->before === null ? null : $this->claimBefore(...), $this->after === null ? null : self::after(...)];
		return ['*' => ['semicolon' => $claim, 'firstSemicolon' => $claim, 'secondSemicolon' => $claim, 'leadingSemicolon' => $claim]];
	}


	/**
	 * A close tag ending a statement, as in a template's echo, is no semicolon to write tight; a semicolon below the end
	 * of a heredoc, where PHP before 7.3 wanted it, keeps its line, and by the option so does one below a statement
	 * spanning several lines.
	 */
	private function claimBefore(Gap $gap): ?Claim
	{
		static $hug = new Claim(Space::None, line: Line::Same);
		$token = $gap->token;
		$previous = $token->getPrevious();
		$first = $token->parent?->getFirstToken();
		return match (true) {
			$token->is(TokenKind::CloseTag) => null,
			$previous?->is(TokenKind::EndHeredoc) ?? false => Claim::none(),
			$this->allowOwnLine && $previous !== null && $first !== null && $first !== $token && $first->getLine() !== $previous->getLine() => Claim::none(),
			default => $hug,
		};
	}


	/** Nothing follows the `;` of `for (;;)`, and what follows the one of __halt_compiler() or stands before ?> is not code. */
	private static function after(Gap $gap): ?Claim
	{
		$token = $gap->token;
		$next = $token->getNext();
		return $token->is(TokenKind::CloseTag) || $next === null || $next->is(';', ')', TokenKind::CloseTag, TokenKind::HaltCompilerData)
			? null
			: Claim::single();
	}
}
