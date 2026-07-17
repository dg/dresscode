<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\CaseNode;


/**
 * No whitespace before the colon of a case: `case 1:`, `default:`.
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
		return [CaseNode::class => ['separator' => [Claim::none(), null]]];
	}
}
