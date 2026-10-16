<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Domain, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\Expression\ArrayAccessNode;


/**
 * No whitespace around the brackets of an offset access, which stay on one line: `$a[$i]`, not `$a [ $i ]`.
 */
#[RuleInfo(Stage::Formatting)]
final class OffsetBracketSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'spacing.padding.offset',
			Domain::state(),
			'The whitespace inside the brackets of an offset access and before them',
		)];
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		return [
			ArrayAccessNode::class => [
				'openBracket' => [$hug, $hug],
				'closeBracket' => [$hug, null],
			],
		];
	}
}
