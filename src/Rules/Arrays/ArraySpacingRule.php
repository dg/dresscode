<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\DestructuringNode;
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * No whitespace just inside the brackets of an array or a destructuring on a line, nor between `array` and its
 * parenthesis, which stay on one line: `[1, 2]`, `[$a, $b] = $x` and `array(1)`, not `[ 1, 2 ]` and `array (1)`.
 */
#[RuleInfo(Stage::Formatting)]
final class ArraySpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.arrayBrackets', new Shapes(['compact' => ['[1, 2]', 'none inside']]), 'The whitespace inside the brackets of an array or a destructuring, and between `array` and its parenthesis')];
	}


	public function getClaims(): array
	{
		return [
			ArrayNode::class => [
				'arrayKeyword' => [null, new Claim(Space::None, line: Line::Same)],
				'openDelimiter' => [null, Claim::noSpace()],
				'closeDelimiter' => [Claim::noSpace(), null],
			],
			DestructuringNode::class => [
				'openDelimiter' => [null, Claim::noSpace()],
				'closeDelimiter' => [Claim::noSpace(), null],
			],
		];
	}
}
