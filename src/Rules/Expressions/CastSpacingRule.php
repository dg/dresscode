<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\CastNode;


/**
 * A single space, or none, between a cast and its operand, which stays on the line of the cast.
 */
#[RuleInfo(Stage::Formatting)]
final class CastSpacingRule extends GapRule
{
	private Claim $claim;


	public static function getDecisions(): array
	{
		return [new Decision('spacing.cast', new Shapes([
			'spaced' => ['(int) $x', 'a single space after the cast'],
			'compact' => ['(int)$x', 'no space after the cast'],
		]), 'The space between a cast and its operand, which stays on the line of the cast')];
	}


	public function configure(Values $values): void
	{
		$this->claim = new Claim($values->get('spacing.cast')->getShape() === 'spaced' ? Space::Single : Space::None, line: Line::Same);
	}


	public function getClaims(): array
	{
		return [CastNode::class => ['operator' => [null, $this->claim]]];
	}
}
