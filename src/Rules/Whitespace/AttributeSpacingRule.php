<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, GapRule, RuleInfo, Stage};
use PhpSyntax\Nodes\AttributeGroupNode;


/**
 * `#[Foo]` hugs its brackets: no whitespace after `#[` or before `]` on the same line, a single space between
 * the last group and what follows it on the line. A group spanning lines is left to its author; where the
 * groups of a declaration stand is the matter of dresscode/attribute-position, and the empty parentheses of
 * `Foo()` of dresscode/useless-attribute-parentheses.
 */
#[RuleInfo(
	'dresscode/attribute-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside the brackets of an attribute group',
)]
final class AttributeSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [
			AttributeGroupNode::class => [
				'openAttribute' => [null, Claim::none()],
				'closeBracket' => [Claim::none(), null],
			],
			'*' => ['attributes' => [null, Claim::single()]],
		];
	}
}
