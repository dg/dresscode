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
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * A single space, or none, around the concatenation operator, unless it sits at a line break.
 */
#[RuleInfo(
	'dresscode/concat-spacing',
	Stage::Formatting,
	description: 'Puts a single space around the concatenation operator',
)]
final class ConcatSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $claim;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('single', 'none')->default('single'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = $options['spacing'] === 'single' ? Claim::single() : Claim::none();
	}


	public function getClaims(): array
	{
		$concat = fn(Gap $gap): ?Claim => $gap->token->text === '.' ? $this->claim : null;
		return [BinaryOpNode::class => ['operator' => [$concat, $concat]]];
	}
}
