<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CatchNode, NameNode};
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;


/**
 * Code catching or typing the general `\Exception` misses errors; `\Throwable` covers both. Only the types of a
 * catch and of a declaration are looked at, and a catch of Exception followed by a catch of Throwable is fine.
 * Reported only: the replacement would change what the code catches.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class ThrowableForExceptionRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.exceptionWhereThrowableBelongs', Domain::state('forbidden'), '`catch (Exception)` and `@throws Exception` where `Throwable` is meant')];
	}


	public function getVisitedNodes(): array
	{
		return [CatchNode::class, NamedTypeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if ($node instanceof NamedTypeNode) {
			$names = [$node->name];
		} elseif ($node instanceof CatchNode && !self::isFollowedByThrowableCatch($node, $resolver)) {
			$names = $node->types->getItems();
		} else {
			return;
		}

		foreach ($names as $name) {
			if (self::isClass($name, 'Exception', $resolver)) {
				$context->report($name, 'The type `Exception` must be `Throwable`, which also covers errors.', fixable: false);
			}
		}
	}


	private static function isFollowedByThrowableCatch(CatchNode $catch, NameResolver $resolver): bool
	{
		$try = $catch->parent?->parent;
		if (!$try instanceof TryNode) {
			return false;
		}

		$catches = $try->catches->getItems();
		foreach (array_slice($catches, $try->catches->indexOf($catch) + 1) as $later) {
			foreach ($later->types->getItems() as $type) {
				if (self::isClass($type, 'Throwable', $resolver)) {
					return true;
				}
			}
		}

		return false;
	}


	private static function isClass(NameNode $name, string $class, NameResolver $resolver): bool
	{
		return $name->isReference() // a builtin type names no class
			&& strcasecmp($resolver->resolveClass($name), $class) === 0;
	}
}
