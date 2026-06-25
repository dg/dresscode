<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Nodes\MatchArmNode;
use PhpSyntax\TokenKind;


/**
 * No whitespace before a comma, which stays on the line of what is before it, and a single space after it
 * unless the line ends there. Whitespace wider than that aligns the columns of a table, and the option says
 * which of it stays: the one made of tabs, the one made of spaces, either, or none.
 */
#[RuleInfo(
	'dresscode/comma-spacing',
	Stage::Formatting,
	description: 'Puts a single space after a comma and none before it',
)]
final class CommaSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $alignment;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'alignment' => Expect::anyOf('none', 'spaces', 'tabs', 'keep')->default('tabs')
				->description('Which alignment after a comma stays: none collapses it to a single space, spaces and tabs keep the one written with them, keep keeps any'),
		]);
	}


	public function configure(array $options): void
	{
		$this->alignment = match ($options['alignment']) {
			'none' => Claim::single(),
			'spaces' => Claim::atLeastSingle(),
			'tabs' => Claim::singleOrTabs(),
			default => Claim::atLeastSingleOrTabs(),
		};
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$before = fn(Gap $gap): ?Claim => match (true) {
			// the comma after a skipped item of a destructuring list keeps its space: [$a, , $b]
			!$gap->token->is(',') || ($gap->token->getPrevious()?->is(',') ?? false) => null,
			// below the end of a heredoc, where PHP before 7.3 wanted it, a comma may keep its line
			$gap->token->getPrevious()?->is(TokenKind::EndHeredoc) ?? false => Claim::none(),
			default => $hug,
		};
		$after = fn(Gap $gap): ?Claim => $gap->token->is(',') ? $this->alignment : null;
		return [
			'*' => ['*:separator' => [$before, $after]],
			MatchArmNode::class => ['defaultComma' => [$hug, $this->alignment]],
		];
	}
}
