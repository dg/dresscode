<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\ArrayAccessNode;


/**
 * No whitespace around the brackets of an offset access on a line: `$a[$i]`, not `$a [ $i ]`.
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
		return [
			ArrayAccessNode::class => [
				'openBracket' => [Claim::none(), Claim::none()],
				'closeBracket' => [Claim::none(), null],
			],
		];
	}
}
