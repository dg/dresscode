<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\CastNode;


/**
 * The canonical spelling of a cast: the short name in lowercase and no whitespace inside the parentheses,
 * `(int)`, not `(Integer)` or `( int )`. The space between the cast and its operand is the matter of
 * dresscode/cast-spacing.
 */
#[RuleInfo(
	'dresscode/cast-canonical-type',
	Stage::Structure,
	description: 'Writes a cast with the short type name in lowercase',
	group: Group::Deprecations,
)]
final class CastCanonicalTypeRule extends NodeRule
{
	private const ShortNames = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float', 'real' => 'float', 'binary' => 'string'];


	public function getVisitedTypes(): array
	{
		return [CastNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof CastNode) {
			return;
		}

		$cast = $node->cast;
		$name = strtolower(trim($cast->text, "() \t"));
		$canonical = '(' . (self::ShortNames[$name] ?? $name) . ')';
		if ($canonical !== $cast->text && $context->report($cast, "The cast must be written '$canonical'")) {
			$cast->setText($canonical);
		}
	}
}
