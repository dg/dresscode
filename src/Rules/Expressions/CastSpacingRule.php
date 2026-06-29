<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, ConfigurableRule, GapRule, Line, RuleInfo, Space, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Nodes\Expression\CastNode;


/**
 * A single space, or none, between a cast and its operand, which stays on the line of the cast. The spelling
 * of the cast itself is the matter of dresscode/cast-canonical-type.
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
		$this->claim = new Claim($options['spacing'] === 'single' ? Space::Single : Space::None, line: Line::Same);
	}


	public function getClaims(): array
	{
		return [CastNode::class => ['cast' => [null, $this->claim]]];
	}
}
