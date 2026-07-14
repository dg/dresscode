<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * Whitespace around `?` and `:` of a ternary, and around `?:` as a whole when the middle operand is left out:
 * at least one space, as the standards word it, or exactly one; an operator at a line break is left alone.
 */
#[RuleInfo(
	'dresscode/ternary-operator-spacing',
	Stage::Formatting,
	description: 'Puts whitespace around the ternary operators',
)]
final class TernaryOperatorSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $claim;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('atLeastSingle', 'single')->default('atLeastSingle')
				->description('atLeastSingle keeps any number of spaces around the operators, single collapses them to one'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = $options['spacing'] === 'single' ? Claim::single() : Claim::atLeastSingle();
	}


	public function getClaims(): array
	{
		$short = fn(Gap $gap) => $gap->token->parent instanceof TernaryNode && $gap->token->parent->then === null;
		return [
			TernaryNode::class => [
				'question' => [$this->claim, fn(Gap $gap) => $short($gap) ? Claim::none() : $this->claim],
				'colon' => [fn(Gap $gap) => $short($gap) ? Claim::none() : $this->claim, $this->claim],
			],
		];
	}
}
