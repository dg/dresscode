<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * An array turns a null key into an empty string, and PHP 8.5 deprecated letting it: `$a[null]` and
 * `array_key_exists(null, $a)` say `$a['']` and `array_key_exists('', $a)`. Only a null the code writes out
 * is read; a null that arrives in a variable the code does not show.
 */
#[RuleInfo(
	'dresscode/no-null-array-key',
	Stage::Structure,
	description: 'Writes a null array key as the empty string it becomes',
	group: Group::Deprecations,
)]
final class NoNullArrayKeyRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\ArrayAccessNode::class, Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (version_compare($context->getPhpVersion(), '8.5', '<')) {
			return;
		}

		$key = match (true) {
			$node instanceof Expression\ArrayAccessNode => $node->index,
			$node instanceof Expression\FunctionCallNode
			&& !$node->arguments->isPartialApplication()
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node, 'array_key_exists')
				=> $node->arguments->findArgument('key', 0)?->value,
			default => null,
		};
		if (
			!$key instanceof NullNode
			|| !$context->report($key, "The null key must be written '', which is what the array makes of it")
		) {
			return;
		}

		$key->replaceWith((new Parser)->parseExpression("''"));
	}
}
