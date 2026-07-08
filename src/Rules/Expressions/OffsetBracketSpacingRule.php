<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
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
