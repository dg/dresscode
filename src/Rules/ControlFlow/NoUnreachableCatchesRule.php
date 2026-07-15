<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\TryNode;


/**
 * A catch after one catching Throwable can never run; reported, never removed. A catch after one of its parent
 * class is not told from a reachable one, the rule not asking what the classes extend.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoUnreachableCatchesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.unreachableCatch', Domain::state('forbidden'), 'A `catch` after one catching `Throwable`')];
	}


	public function getVisitedNodes(): array
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
				$context->report($catch, 'Unreachable catch block, because a previous one catches `Throwable`.', fixable: false);
				continue;
			}

			foreach ($catch->types->getItems() as $type) {
				$dead = $dead || strcasecmp($resolver->resolveClass($type), 'Throwable') === 0;
			}
		}
	}
}
