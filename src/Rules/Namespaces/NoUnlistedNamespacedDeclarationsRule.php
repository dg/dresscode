<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, DecisionKind, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Names;
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\FileNode;


/**
 * A function or a constant declared in a namespace is listed in the namespaces of the configuration once it says
 * nameResolution: certain, because an unqualified name in that namespace is then taken as global
 * wherever the lists do not name it. A declaration inside a condition counts, and so does `define()` with the name
 * written as a string. The key turns the rule on, and under an uncertain resolution it has nothing to guard.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class, NamespacedSymbols::class])]
final class NoUnlistedNamespacedDeclarationsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [
			new Decision('namespaces.functions', new Names, 'The functions the namespaces of the project declare, each of them listed once the names are certain', kind: DecisionKind::Fact, default: []),
			new Decision('namespaces.constants', new Names, 'The constants the namespaces of the project declare, each of them listed once the names are certain', kind: DecisionKind::Fact, default: []),
		];
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode || !($symbols = $context->getAnalysis(NamespacedSymbols::class))->complete) {
			return;
		}

		foreach ($context->getAnalysis(NameResolver::class)->findNamespacedDeclarations($node) as $declaration) {
			$name = $declaration->name;
			if ($declaration->kind === SymbolKind::Function && !$symbols->hasFunction($name)) {
				$context->report($declaration->node, "Function `$name()` must be listed in `namespaces.functions`.", 'namespaces.functions', fixable: false);
			} elseif ($declaration->kind === SymbolKind::Constant && !$symbols->hasConstant($name)) {
				$context->report($declaration->node, "Constant `$name` must be listed in `namespaces.constants`.", 'namespaces.constants', fixable: false);
			}
		}
	}
}
