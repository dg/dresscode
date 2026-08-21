<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;


/**
 * An argument PHP has stopped reading and then deprecated goes: the call does the same without it, and the
 * version that removes the parameter will refuse it. Only an argument standing last is taken, so that no
 * other argument moves, and only one that would do nothing when it ran, so that dropping it drops nothing.
 */
#[RuleInfo(
	'dresscode/no-deprecated-arguments',
	Stage::Structure,
	description: 'Removes the arguments the targeted version of PHP deprecated because nothing reads them',
	group: Group::Deprecations,
)]
final class NoDeprecatedArgumentsRule extends NodeRule
{
	/** function => the name of the parameter, the position it stands at, and the version that deprecated it */
	private const Arguments = [
		'finfo_buffer' => ['context', 3, '8.5'],
		'get_defined_functions' => ['exclude_disabled', 0, '8.5'],
		'openssl_pkey_derive' => ['key_length', 2, '8.5'],
	];


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode || $node->arguments->isPartialApplication() || $node->hasComment()) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach (self::Arguments as $function => [$parameter, $position, $since]) {
			$argument = $resolver->isGlobalFunctionCall($node, $function)
				? $node->arguments->findArgument($parameter, $position)
				: null;
			$items = $node->arguments->items->getItems();
			if (
				$argument === null
				|| $argument !== end($items)
				|| !($argument->value->isRepeatableRead() || $argument->value->hasValue())
				|| version_compare($context->getPhpVersion(), $since, '<')
				|| !$context->report($argument, "The $parameter argument of $function() is deprecated since PHP $since, nothing reads it")
			) {
				continue;
			}

			$node->arguments->items->removeItem($argument);
			return;
		}
	}
}
