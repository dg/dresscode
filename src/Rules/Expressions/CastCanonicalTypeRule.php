<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\CastNode;


/**
 * The canonical spelling of a cast: the short name in lowercase and no whitespace inside the parentheses,
 * `(int)`, not `(Integer)` or `( int )`. The space between the cast and its operand is the matter of
 * `CastSpacingRule`.
 */
#[RuleInfo(Stage::Structure)]
final class CastCanonicalTypeRule extends NodeRule
{
	private const ShortNames = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float', 'real' => 'float', 'binary' => 'string'];


	public static function getDecisions(): array
	{
		return [new Decision('builtin.castType', new Words(['short' => '`(int)`, `(bool)`, `(float)` in lowercase, never `(integer)`, `(Boolean)`, `(double)`']), 'The type name of a cast')];
	}


	public function getVisitedNodes(): array
	{
		return [CastNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof CastNode) {
			return;
		}

		$cast = $node->operator;
		$name = strtolower(trim($cast->text, "() \t"));
		$canonical = '(' . (self::ShortNames[$name] ?? $name) . ')';
		if ($canonical !== $cast->text && $context->report($cast, "The cast `$cast->text` must be written `$canonical`.")) {
			$cast->setText($canonical);
		}
	}
}
