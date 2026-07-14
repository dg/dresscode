<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\Expression\CastNode;


/**
 * A single space, or none, between a cast and its operand. The spelling of the cast itself is
 * cast-canonical-type.
 */
#[RuleInfo(
	'dresscode/cast-spacing',
	Stage::Formatting,
	description: 'Puts a single space between a cast and its operand',
)]
final class CastSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $claim;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('single', 'none')->default('single')->description('Between the cast and its operand'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = $options['spacing'] === 'single' ? Claim::single() : Claim::none();
	}


	public function getClaims(): array
	{
		return [CastNode::class => ['cast' => [null, $this->claim]]];
	}
}
