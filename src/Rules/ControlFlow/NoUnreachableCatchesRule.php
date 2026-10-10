<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\TryNode;


/**
 * A catch whose every class a previous one catches already, `Throwable`, the class itself or, with the types, a class it
 * extends, can never run; reported, never removed. Without the types a catch after one of its parent class is not told
 * from a reachable one.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class, Types::class])]
final class NoUnreachableCatchesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.unreachableCatch', Domain::state('forbidden'), 'A `catch` after one catching `Throwable` or a class it catches')];
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
		$types = $context->findAnalysis(Types::class);
		$caught = [];
		foreach ($node->catches->getItems() as $catch) {
			$classes = array_map(fn($type) => $resolver->resolveClass($type), $catch->types->getItems());
			$by = array_find($caught, fn(string $previous) => array_all(
				$classes,
				fn(string $class) => strcasecmp($previous, 'Throwable') === 0 || $types?->isSubtype($class, $previous) === Tristate::Yes || strcasecmp($class, $previous) === 0,
			));
			if ($by !== null) {
				$context->report($catch, "Unreachable catch block, because a previous one catches `$by`.", fixable: false);
			}

			array_push($caught, ...$classes);
		}
	}
}
