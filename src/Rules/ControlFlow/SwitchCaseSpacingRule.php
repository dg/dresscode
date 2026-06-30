<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\CaseNode;


/**
 * No whitespace before the colon of a case, which stays on the line of its value: `case 1:`, `default:`.
 */
#[RuleInfo(
	'dresscode/switch-case-spacing',
	Stage::Formatting,
	description: 'Removes whitespace before the colon of a case',
)]
final class SwitchCaseSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [CaseNode::class => ['separator' => [new Claim(Space::None, line: Line::Same), null]]];
	}
}
