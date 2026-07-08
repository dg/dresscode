<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\Member\TraitAliasNode;
use PhpSyntax\Nodes\Member\TraitPrecedenceNode;


/**
 * No whitespace around `::`, nor inside the braces of a dynamic name: `A::{$b}()`, all of it on one line.
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
			StaticPropertyFetchNode::class => $colon,
			TraitPrecedenceNode::class => $colon,
			TraitAliasNode::class => $colon,
		];
	}
}
