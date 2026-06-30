<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\Expression\AssignmentByReferenceNode;


/**
 * No whitespace between `&` and its operand, which stays on its line: `&$a`, `function &f()`, `$a = &$b`.
 */
#[RuleInfo(
	'dresscode/reference-spacing',
	Stage::Formatting,
	description: 'Removes whitespace between & and its operand',
)]
final class ReferenceSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		return [
			'*' => ['ampersand' => [null, $hug]],
			AssignmentByReferenceNode::class => ['ampersand' => [null, $hug]],
		];
	}
}
