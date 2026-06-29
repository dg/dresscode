<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, GapRule, Line, RuleInfo, Space, Stage};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\{TraitAliasNode, TraitPrecedenceNode};


/**
 * No whitespace around `::`, nor after the `$` or inside the braces of a dynamic name: `A::{$b}()`, `A::$$b`,
 * `A::${$b}`, all of it on one line.
 */
#[RuleInfo(
	'dresscode/double-colon-spacing',
	Stage::Formatting,
	description: 'Removes whitespace around the double colon',
)]
final class DoubleColonSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		$colon = ['doubleColon' => [$hug, $hug]];
		$braces = ['openBrace' => [null, $hug], 'closeBrace' => [$hug, null]];
		return [
			StaticMethodCallNode::class => $colon + $braces,
			ClassConstantFetchNode::class => $colon + $braces,
			StaticPropertyFetchNode::class => $colon + $braces + ['dollar' => [null, $hug]],
			TraitPrecedenceNode::class => $colon,
			TraitAliasNode::class => $colon,
		];
	}
}
