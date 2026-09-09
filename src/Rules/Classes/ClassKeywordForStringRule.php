<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, SymbolKind, Token};
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * A string naming a class, interface, trait or enum is its `::class`: `'Acme\Shop\Order'` is `Order::class`, written
 * the way the scope reaches the name, so that a reader, an IDE and a rename see the reference. The types of the
 * project decide what is a class, which is what a string alone cannot say; a name nothing the project has declares
 * stays a string, and so does one in another letter case than the declaration, whose `::class` a rule of the casing
 * of names would then turn into another string. A string without a backslash stays too, `'Exception'` being a word
 * as often as a class.
 *
 * `::class` gives the name exactly as the string holds it, so the fix changes nothing, but for a string with a leading
 * backslash, which `::class` drops: that fix is risky.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, NameResolver::class])]
final class ClassKeywordForStringRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('literals.classNameInString', Domain::state('forbidden'), '`\'Acme\\Shop\\Order\'` is `Order::class`')];
	}


	public function getVisitedNodes(): array
	{
		return [StringNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof StringNode
			|| !str_contains($node->token->text, '\\') // the value holds a backslash only where the literal does
			|| !preg_match('~^\\\\?([a-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[a-z_\x80-\xff][\w\x80-\xff]*)*)$~iD', $node->toValue(), $m)
		) {
			return;
		}

		$name = $m[1];
		if (
			!str_contains($node->toValue(), '\\')
			|| $context->getAnalysis(Types::class)->findClassName($name) !== $name
			|| !$context->report(
				$node,
				"The class name must be written `$name::class` instead of a string.",
				risk: str_starts_with($node->toValue(), '\\') ? Risk::BehaviorChanges : null,
				because: str_starts_with($node->toValue(), '\\') ? '`::class` drops the leading backslash of the string' : null,
			)
		) {
			return;
		}

		$short = $context->getAnalysis(NameResolver::class)->shortenName($name, SymbolKind::ClassLike, $node);
		$node->replaceWith((new Builder)->classConstantFetch($short, 'class'));
	}
}
