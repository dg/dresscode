<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\Member\TraitAliasNode;
use PhpSyntax\Nodes\Member\TraitPrecedenceNode;


/**
 * No whitespace around `::` on a line, nor inside the braces of a dynamic name: `A::{$b}()`.
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
		$colon = ['doubleColon' => [Claim::none(), Claim::none()]];
		$braces = ['openBrace' => [null, Claim::none()], 'closeBrace' => [Claim::none(), null]];
		return [
			StaticMethodCallNode::class => $colon + $braces,
			ClassConstantFetchNode::class => $colon + $braces,
			StaticPropertyFetchNode::class => $colon,
			TraitPrecedenceNode::class => $colon,
			TraitAliasNode::class => $colon,
		];
	}
}
