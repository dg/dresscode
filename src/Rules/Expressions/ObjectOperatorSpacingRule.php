<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\{MethodCallNode, PropertyFetchNode};


/**
 * No whitespace around `->` and `?->` on a line, nor inside the braces of a dynamic name: `$a->{$b}`. The name
 * stays on the line of the operator.
 */
#[RuleInfo(Stage::Formatting)]
final class ObjectOperatorSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.objectOperator', new Shapes(['compact' => ['$a->b()', 'none around']]), 'The whitespace around the object operator')];
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$slots = [
			'operator' => [Claim::noSpace(), $hug],
			'openBrace' => [null, $hug],
			'closeBrace' => [$hug, null],
		];
		return [MethodCallNode::class => $slots, PropertyFetchNode::class => $slots];
	}
}
