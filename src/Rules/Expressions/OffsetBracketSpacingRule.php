<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\ArrayAccessNode;


/**
 * No whitespace around the brackets of an offset access, which stay on one line: `$a[$i]`, not `$a [ $i ]`.
 */
#[RuleInfo(Stage::Formatting)]
final class OffsetBracketSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.offsetBrackets', new Shapes(['compact' => ['$a[0]', 'none around and inside']]), 'The whitespace around and inside the brackets of an offset access')];
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
