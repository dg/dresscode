<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\TryNode;


/**
 * A catch after one catching Throwable can never run; reported, never removed. Without the types, a catch after
 * one of its parent class is not told from a reachable one.
 */
#[RuleInfo(
	'dresscode/no-unreachable-catch',
	Stage::Structure,
	description: 'Reports a catch block following one that catches Throwable',
	group: Group::Correctness,
)]
final class NoUnreachableCatchRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [TryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TryNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$dead = false;
		foreach ($node->catches->getItems() as $catch) {
			if ($dead) {
				$context->report($catch, 'Unreachable catch block, a previous one catches Throwable');
				continue;
			}

			foreach ($catch->types->getItems() as $type) {
				$dead = $dead || strcasecmp($resolver->resolveClass($type), 'Throwable') === 0;
			}
		}
	}
}
