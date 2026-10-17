<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, DecisionKind, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Flag;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};


/**
 * No import that the code does not use; a name a doc comment writes uses the import too where
 * `phpdoc.namesUseImports` says so.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpDoc::class])]
final class NoUnusedImportsRule extends NodeRule
{
	private const Unused = 'imports.unused';
	private const Annotations = 'phpdoc.namesUseImports';

	private bool $annotations = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Unused, Domain::state('forbidden'), 'An import nothing uses is removed'),
			new Decision(
				self::Annotations,
				new Flag,
				'A class name in a doc comment, the name of an annotation such as `@DB\Entity` included, is resolved through the imports, which it therefore keeps in use',
				kind: DecisionKind::Parameter,
				default: true,
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->annotations = $values->get(self::Annotations)->getFlag();
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode && !$node instanceof NamespaceNode) {
			return;
		}

		$imports = [];
		foreach ($node->statements->getItems() as $stmt) {
			if ($stmt instanceof UseNode) {
				foreach ($stmt->items->getItems() as $item) {
					$alias = $item->alias === null ? $item->name->shortName : $item->alias->text;
					$imports[] = [$item, $item->symbolKind, $alias, NameReferences::toKey($item->symbolKind, $alias)];
				}
			}
		}

		if (!$imports) {
			return;
		}

		$used = NameReferences::collectAliasUsages($node);
		// a doc comment can use only a class, so it is read when the code leaves an imported class unused
		if (
			$this->annotations
			&& array_any(
				$imports,
				fn(array $import) => $import[1] === SymbolKind::ClassLike && !isset($used[SymbolKind::ClassLike->name][$import[3]]),
			)
		) {
			$used[SymbolKind::ClassLike->name] += NameReferences::collectDocAliasUsages($node, $context->getAnalysis(PhpDoc::class));
		}

		foreach ($imports as [$item, $kind, $alias, $key]) {
			$shown = $kind === SymbolKind::Function ? "$alias()" : $alias;
			if (isset($used[$kind->name][$key]) || !$context->report($item, "The import of `$shown` is unused.")) {
				continue;
			}

			NodeHelpers::removeMergingGaps($item, $context->style->lineEnding);
		}
	}
}
