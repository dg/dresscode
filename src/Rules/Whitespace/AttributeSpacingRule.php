<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, GapRule, RuleInfo, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\AttributeGroupNode;


/**
 * `#[Foo]` hugs its brackets: no whitespace after `#[` or before `]` on the same line, a single space between
 * the last group and what follows it on the line. A group spanning lines is left to its author; where the
 * groups of a declaration stand is the matter of `AttributePositionRule`, and the empty parentheses of
 * `Foo()` of `EmptyArgumentParenthesesRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class AttributeSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.attribute', new Shapes(['compact' => ['#[Foo($a)]', 'none inside the brackets, none before the parenthesis']]), 'The whitespace inside the brackets of an attribute group and before the parenthesis of its arguments')];
	}


	public function getClaims(): array
	{
		return [
			AttributeGroupNode::class => [
				'openBracket' => [null, Claim::noSpace()],
				'closeBracket' => [Claim::noSpace(), null],
			],
			'*' => ['attributes' => [null, Claim::singleSpace()]],
		];
	}
}
