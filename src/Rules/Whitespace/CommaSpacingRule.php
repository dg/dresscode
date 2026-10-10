<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, DecisionKind, Domain, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\MatchArmNode;
use PhpSyntax\Token;


/**
 * No whitespace before a comma, which stays on the line of what is before it, and a single space after it
 * unless the line ends there. Whitespace wider than that aligns the columns of a table, and
 * `spacing.comma.alignment` says which of it stays: the one made of tabs, the one made of spaces, either, or none.
 */
#[RuleInfo(Stage::Formatting)]
final class CommaSpacingRule extends GapRule
{
	private const Alignment = 'spacing.comma.alignment';

	private Claim $spaceAfterComma;


	public static function getDecisions(): array
	{
		return [
			new Decision('spacing.comma.around', new Shapes(['spaced' => ['$a, $b', 'none before, a single space after']]), 'The whitespace around a comma, which stays on the line of what is before it, a line ending after it being free'),
			new Decision(self::Alignment, Domain::alignment(), 'Which whitespace wider than a single space after a comma stays, aligning the columns of a table; alignment is never made', kind: DecisionKind::Parameter, default: 'tabs'),
		];
	}


	public function configure(Values $values): void
	{
		$this->spaceAfterComma = match ($values->get(self::Alignment)->getWord()) {
			'none' => Claim::singleSpace(),
			'spaces' => Claim::atLeastOneSpace(),
			'tabs' => Claim::singleSpaceOrTabs(),
			default => Claim::atLeastOneSpaceOrTabs(),
		};
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$before = fn(Gap $gap): ?Claim => match (true) {
			// the comma after a skipped item of a destructuring list keeps its space: [$a, , $b]
			!$gap->token->is(',') || ($gap->token->getPrevious()?->is(',') ?? false) => null,
			// below the end of a heredoc, where PHP before 7.3 wanted it, a comma may keep its line
			$gap->token->getPrevious()?->is(Token::EndHeredoc) ?? false => Claim::noSpace(),
			default => $hug,
		};
		$after = fn(Gap $gap): ?Claim => $gap->token->is(',') ? $this->spaceAfterComma : null;
		return [
			'*' => ['*:separator' => [$before, $after]],
			MatchArmNode::class => ['comma' => [$hug, $this->spaceAfterComma]],
		];
	}
}
