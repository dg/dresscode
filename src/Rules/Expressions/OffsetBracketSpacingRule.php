<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\Expression\ArrayAccessNode;


/**
 * No whitespace around the brackets of an offset access, which stay on one line: `$a[$i]`, not `$a [ $i ]`.
 */
#[RuleInfo(
	'dresscode/offset-bracket-spacing',
	Stage::Formatting,
	description: 'Removes whitespace around the brackets of an offset access',
)]
final class OffsetBracketSpacingRule extends GapRule
{
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
