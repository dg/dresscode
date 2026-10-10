<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CatchNode, NameNode, ParameterNode};
use PhpSyntax\Nodes\Statement\TryNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;


/**
 * Code catching the general `\Exception`, or taking it as a parameter as a handler of what was thrown does, misses
 * errors; `\Throwable` covers both. A catch or a type naming `\Error` or `\Throwable` beside it covers them already,
 * and so does a catch of Exception followed by a catch of Throwable; a promoted parameter is a property, which says
 * what it holds rather than what may be thrown. Reported only: the replacement would change what the code catches.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class ThrowableForExceptionRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.exceptionWhereThrowableBelongs', Domain::state('forbidden'), '`catch (Exception)` and a parameter of the type `Exception` where `Throwable` is meant')];
	}


	public function getVisitedNodes(): array
	{
		return [CatchNode::class, ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if ($node instanceof ParameterNode && $node->type !== null && !$node->promoted) {
			$names = array_map(
				fn(NamedTypeNode $type) => $type->name,
				$node->type instanceof NamedTypeNode ? [$node->type] : $node->type->find(NamedTypeNode::class),
			);
		} elseif ($node instanceof CatchNode && !self::isFollowedByThrowableCatch($node, $resolver)) {
			$names = $node->types->getItems();
		} else {
			return;
		}

		if (array_any($names, fn(NameNode $name) => self::resolvesTo($name, 'Error', $resolver) || self::resolvesTo($name, 'Throwable', $resolver))) {
			return;
		}

		foreach ($names as $name) {
			if (self::resolvesTo($name, 'Exception', $resolver)) {
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
				if (self::resolvesTo($type, 'Throwable', $resolver)) {
					return true;
				}
			}
		}

		return false;
	}


	/** Whether the name names the class, not a builtin type. */
	private static function resolvesTo(NameNode $name, string $class, NameResolver $resolver): bool
	{
		return $name->isReference()
			&& strcasecmp($resolver->resolveClass($name), $class) === 0;
	}
}
