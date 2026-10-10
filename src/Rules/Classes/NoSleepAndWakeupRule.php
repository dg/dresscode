<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\PhpSymbols;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, NameNode};
use PhpSyntax\Nodes\Member\{MethodNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\ClassNode;
use function in_array;


/**
 * PHP 8.5 soft-deprecated `__sleep()` and `__wakeup()` in favor of `__serialize()` and `__unserialize()`.
 * The rule reports them and rewrites neither: `__sleep()` returns the names of the properties to keep and
 * `__serialize()` their values, and a class that gains `__serialize()` writes a serialized form the old one
 * cannot read, so every payload already stored somewhere stops loading. What to do with those is the
 * project's decision, not a fixer's.
 *
 * The one it removes is an empty `__wakeup()` that stands for nothing: in a final or anonymous class, since a child
 * may call `parent::__wakeup()`, that inherits no method, from a parent or a trait, whose own empty one would hide it,
 * and implements no interface but PHP's, where one could require it; without the types, a parent or a trait without
 * such a method is not told from one with it. Writing
 * an empty `__unserialize()` instead would be wrong, since a class that has it gets no property restored by PHP.
 * A comment in or above the method keeps it, as it says why the method is there.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpSymbols::class, NameResolver::class])]
final class NoSleepAndWakeupRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.sleepAndWakeup', Domain::state('forbidden'), '`__sleep()` and `__wakeup()`, which PHP 8.5 soft-deprecated')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| !in_array(strtolower($node->name->text), ['__sleep', '__wakeup'], true)
		) {
			return;
		}

		if ($this->isRemovable($node, $context)) {
			if ($context->report($node->name, "Useless method `{$node->name->text}()`, because it is empty and soft-deprecated since PHP 8.5.")) {
				$node->remove(mergeBlankLines: true);
			}

			return;
		}

		$replacement = $node->name->equals('__sleep') ? '__serialize()' : '__unserialize()';
		$context->report(
			$node->name,
			"Method `{$node->name->text}()` is soft-deprecated since PHP 8.5 in favor of `$replacement`.",
			fixable: false,
		);
	}


	private function isRemovable(MethodNode $method, RuleContext $context): bool
	{
		$class = $method->findAncestor(ClassLikeNode::class);
		if (
			!$method->name->equals('__wakeup')
			|| $method->body === null
			|| !$method->body->statements->isEmpty()
			|| $method->hasLeadingComment()
			|| $method->hasInnerComment()
			|| $method->hasTrailingComment()
			|| !(($class instanceof ClassNode && $class->modifiers->final) || $class instanceof AnonymousClassNode)
			|| $class->extends !== null
			|| array_any($class->members->getItems(), fn(Node $member) => $member instanceof TraitUseNode)
		) {
			return false;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$symbols = $context->getAnalysis(PhpSymbols::class);
		return !array_any(
			$class->implements?->getItems() ?? [],
			fn(NameNode $interface) => $symbols->findClassName($resolver->resolveClass($interface)) === null,
		);
	}
}
