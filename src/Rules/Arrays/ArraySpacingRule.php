<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\Expression\{ArrayNode, ListNode};


/**
 * No whitespace just inside the brackets of an array or a destructuring on a line, nor between `array` and its
 * parenthesis, which stay on one line: `[1, 2]`, `[$a, $b] = $x` and `array(1)`, not `[ 1, 2 ]` and `array (1)`.
 */
#[RuleInfo(
	'dresscode/array-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside the brackets of an array',
)]
final class ArraySpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [
			ArrayNode::class => [
				'arrayKeyword' => [null, new Claim(Space::None, line: Line::Same)],
				'openDelimiter' => [null, Claim::none()],
				'closeDelimiter' => [Claim::none(), null],
			],
			ListNode::class => [
				'openDelimiter' => [null, Claim::none()],
				'closeDelimiter' => [Claim::none(), null],
			],
		];
	}
}
