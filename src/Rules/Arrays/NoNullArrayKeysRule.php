<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode, Expression};
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * An array turns a null key into an empty string, and PHP 8.5 deprecated letting it: `[null => 1]`, `$a[null]`
 * and `array_key_exists(null, $a)` say `['' => 1]`, `$a['']` and `array_key_exists('', $a)`. Only a null the
 * code writes out is read; a null that arrives in a variable the code does not show. An object implementing
 * `ArrayAccess` gets the null as it is written and an array is not told from such an object, so the fix of an
 * offset is risky. A key of destructuring reads an offset of the value destructured, so it is taken as such an
 * offset is.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoNullArrayKeysRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.nullArrayKey', Domain::state('forbidden'), '`[null => 1]` is `[\'\' => 1]`, which it becomes')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\ArrayAccessNode::class, ArrayItemNode::class, Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$key = match (true) {
			$node instanceof Expression\ArrayAccessNode => $node->index,
			$node instanceof ArrayItemNode => $node->key,
			$node instanceof Expression\FunctionCallNode
			&& !$node->arguments->isPartialApplication()
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node, 'array_key_exists')
				=> $node->arguments->findArgument('key', 0)?->value,
			default => null,
		};
		if (!$key instanceof NullNode) {
			return;
		}

		$offset = $node instanceof Expression\ArrayAccessNode
			|| ($node instanceof ArrayItemNode && $node->parent?->parent instanceof DestructuringNode);
		$uncertainty = $node instanceof Expression\FunctionCallNode ? GlobalCalls::findUncertainty($node, $context) : null;
		if (!$context->report(
			$key,
			"The `null` key must be written `''`, which is what the array makes of it.",
			risk: $offset ? Risk::TypeUnknown : ($uncertainty === null ? null : Risk::NameUncertain),
			because: $offset ? 'an object implementing `ArrayAccess` gets the key as it is written' : $uncertainty,
		)) {
			return;
		}

		$key->replaceWith((new Builder)->expression("''"));
	}
}
