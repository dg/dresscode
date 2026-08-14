<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\TokenKind;


/**
 * Whitespace around a semicolon on its line: none before it, a single space after it when more
 * follows, as in the head of a for loop; `for (;;)` stays. Either side can be left alone by null.
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


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'before' => Expect::anyOf('none', null)->default('none'),
			'after' => Expect::anyOf('single', null)->default('single'),
		]);
	}


	public function configure(array $options): void
	{
		$this->before = $options['before'];
		$this->after = $options['after'];
	}


	public function getClaims(): array
	{
		// a close tag ending a statement, as in a template's echo, is no semicolon to write tight
		$before = $this->before === null ? null : fn(Gap $gap) => $gap->token->is(TokenKind::CloseTag) ? null : Claim::none();
		$claim = [$before, $this->after === null ? null : self::after(...)];
		return ['*' => ['semicolon' => $claim, 'firstSemicolon' => $claim, 'secondSemicolon' => $claim, 'leadingSemicolon' => $claim]];
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
