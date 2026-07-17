<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\MatchArmNode;


/**
 * No whitespace before a comma, a single space after it unless the line ends there. Tabs after
 * a comma align columns and stay, unless the option turns the tolerance off.
 */
#[RuleInfo(
	'dresscode/comma-spacing',
	Stage::Formatting,
	description: 'Puts a single space after a comma and none before it',
)]
final class CommaSpacingRule extends GapRule implements ConfigurableRule
{
	private bool $tabAlignment = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'tabAlignment' => Expect::bool(true)->description('Whitespace with a tab after a comma stays, as it aligns columns'),
		]);
	}


	public function configure(array $options): void
	{
		$this->tabAlignment = $options['tabAlignment'];
	}


	public function getClaims(): array
	{
		$after = $this->tabAlignment ? Claim::singleOrTabs() : Claim::single();
		// the comma after a skipped item of a destructuring list keeps its space: [$a, , $b]
		$before = fn(Gap $gap): ?Claim => $gap->token->is(',') && !($gap->token->getPrevious()?->is(',') ?? false) ? Claim::none() : null;
		return [
			'*' => ['*:separator' => [$before, fn(Gap $gap) => $gap->token->is(',') ? $after : null]],
			MatchArmNode::class => ['defaultComma' => [Claim::none(), $after]],
		];
	}
}
