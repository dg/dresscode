<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameKind, Node, SymbolKind, Token};
use PhpSyntax\Nodes\NameNode;
use function count;


/**
 * Code in the global namespace references classes, functions and constants without the leading backslash:
 * `new Foo`, `strlen()`, `PHP_EOL`, never `\Foo`. A name whose first segment is shadowed by an import keeps
 * it, because there `\Foo` and `Foo` are two different things. Inside a namespace the backslash says which
 * name is meant and stays; the one of an import belongs to dresscode/no-leading-backslash-in-import.
 */
#[RuleInfo(
	'dresscode/no-leading-backslash-in-global-namespace',
	Stage::Structure,
	description: 'Removes the leading backslash of names referenced in the global namespace',
	group: Group::Cleanup,
)]
final class NoLeadingBackslashInGlobalNamespaceRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof NameNode
			|| $node->isDeclaration() // an import is dresscode/no-leading-backslash-in-import
			|| $node->kind !== NameKind::FullyQualified
		) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$role = $node->role;
		$parts = $node->parts;
		// the first segment of a qualified name goes through the class imports whatever the name stands for
		$imports = match (count($parts) > 1 ? SymbolKind::ClassLike : $role) {
			SymbolKind::Function => $resolver->getFunctionImports($node),
			SymbolKind::Constant => $resolver->getConstantImports($node),
			default => $resolver->getClassImports($node),
		};
		if (
			$resolver->getNamespace($node) !== ''
			|| isset($imports[$role === SymbolKind::Constant && count($parts) === 1 ? $parts[0] : strtolower($parts[0])])
			|| !$context->report($node, 'A name in the global namespace must not start with a backslash')
		) {
			return;
		}

		$node->text = implode('\\', $parts);
	}
}
