<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Claim;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;


/**
 * A member of a class, an interface, a trait or an enum starts on its own line when another member precedes
 * it: a case of an enum, a constant, a property, a method, a trait use.
 */
#[RuleInfo(
	'dresscode/single-member-per-line',
	Stage::Formatting,
	description: 'Puts every member of a class on its own line',
)]
final class SingleMemberPerLineRule extends GapRule
{
	public function getClaims(): array
	{
		$break = Claim::nextLine();
		return ['*' => ['members:item' => [fn(Gap $gap) => $gap->index > 0 ? $break : null, null]]];
	}
}
