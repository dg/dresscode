<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * No whitespace just inside the brackets of an array on a line, nor between `array` and its parenthesis, which
 * stay on one line: `[1, 2]` and `array(1)`, not `[ 1, 2 ]` and `array (1)`.
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
		];
	}
}
