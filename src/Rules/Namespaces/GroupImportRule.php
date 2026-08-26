<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, SymbolKind, Token};
use PhpSyntax\Nodes\{FileNode, NodeList, StatementNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, strlen;


/**
 * The imports of one namespace written as a single group use once there are at least minImports of them,
 * `use A\B; use A\C;` becoming `use A\{B, C};`; where a group of that namespace already stands, the names
 * join it, and a second group of it joins the first. The option holds the other way round as well: a group
 * the namespace has fewer names for is written as imports of their own, a group of a single name among them.
 * A name of the global namespace has no namespace to stand under, a statement whose names belong to several
 * namespaces is none of one, and a statement with a comment stays where it is. The rule says nothing
 * while dresscode/import-notation expands group uses, and nothing of a kind it writes combined, those being
 * the two shapes a group cannot live beside. How wide such a group may grow is the matter of
 * dresscode/multi-line-import and their order of dresscode/ordered-imports.
 */
#[RuleInfo(
	'dresscode/group-import',
	Stage::Structure,
	description: 'Writes the imports of one namespace as a single group use declaration, and a group of too few of them as imports of their own',
)]
final class GroupImportRule extends NodeRule implements ConfigurableRule
{
	private int $minImports = 3;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minImports' => Expect::int(3)->min(2)
				->description('The imports of one namespace stand in a group use once there are at least this many of them, and in imports of their own while there are fewer'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minImports = $options['minImports'];
	}


	public function getVisitedTypes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode && !$node instanceof NamespaceNode) {
			return;
		}

		$notation = $context->findRule(ImportNotationRule::class);
		if ($notation !== null && !$notation->keepsGroups()) {
			return;
		}

		// the first group of a namespace and the statements whose names belong in it, a later group among them
		$found = [];
		foreach ($node->statements->getItems() as $stmt) {
			$namespace = $stmt instanceof UseNode && !NodeHelpers::hasComment($stmt) ? self::namespaceOf($stmt) : null;
			if ($namespace === null || $notation?->getShape($stmt->kind) === 'combined') {
				continue;
			}

			$key = $stmt->kind->name . ' ' . $namespace;
			$found[$key] ??= [$namespace, null, []];
			if ($stmt->isGroup() && $found[$key][1] === null) {
				$found[$key][1] = $stmt;
			} else {
				$found[$key][2][] = $stmt;
			}
		}

		foreach ($found as [$namespace, $group, $others]) {
			$names = ($group === null ? 0 : count($group->items))
				+ array_sum(array_map(fn(UseNode $stmt) => count($stmt->items), $others));
			if ($names < $this->minImports) {
				foreach ([$group, ...$others] as $stmt) {
					if ($stmt?->isGroup()) {
						$this->expand($namespace, $stmt, $node->statements, $context);
					}
				}
			} elseif ($group === null ? count($others) > 1 : $others !== []) {
				$this->merge($namespace, $group, $others, $context);
			}
		}
	}


	/**
	 * The namespace every name of the statement stands in; null where they stand in several of them, where one
	 * stands in the global namespace, and where a leading backslash makes the namespace a matter of another rule.
	 */
	private static function namespaceOf(UseNode $stmt): ?string
	{
		if ($stmt->isGroup()) {
			return str_starts_with($stmt->prefix->text, '\\') ? null : $stmt->prefix->text;
		}

		$namespace = null;
		foreach ($stmt->items->getItems() as $item) {
			$name = $item->name->text;
			$position = strrpos($name, '\\');
			if ($position === false || str_starts_with($name, '\\')) {
				return null;
			}

			$own = substr($name, 0, $position);
			if ($namespace !== null && $namespace !== $own) {
				return null;
			}

			$namespace = $own;
		}

		return $namespace;
	}


	/**
	 * The names of the other statements join the group of the namespace, the first of them becoming that group
	 * where none stands there yet; a statement whose report the run refuses keeps its names.
	 * @param list<UseNode> $others
	 */
	private function merge(string $namespace, ?UseNode $group, array $others, RuleContext $context): void
	{
		$host = $group ?? $others[0] ?? null;
		if ($host === null) {
			return;
		}

		$joined = [];
		foreach ($group === null ? array_slice($others, 1) : $others as $stmt) {
			if ($context->report($stmt, "All imports from $namespace in one group use")) {
				$joined[] = $stmt;
			}
		}

		if ($joined === []) {
			return;
		}

		if ($group === null) {
			$group = self::groupOf($host, $namespace);
			if ($group === null) {
				return;
			}

			$host->replaceWith($group);
		}

		foreach ($joined as $stmt) {
			foreach ($stmt->items->getItems() as $item) {
				$group->addImport($item->fullName, $item->alias?->text);
			}

			$stmt->remove();
		}
	}


	/**
	 * A group the namespace has too few names for is written as imports of their own.
	 * @param NodeList<StatementNode> $list
	 */
	private function expand(string $namespace, UseNode $group, NodeList $list, RuleContext $context): void
	{
		if ($context->report($group, "Too few imports from $namespace for a group use")) {
			NodeHelpers::expandGroup($group, $list, $context->getStyle()->eol);
		}
	}


	/** The statement written as a group of the names it imports, which the names of the others then join. */
	private static function groupOf(UseNode $stmt, string $namespace): ?UseNode
	{
		$type = match ($stmt->kind) {
			SymbolKind::Function => 'function ',
			SymbolKind::Constant => 'const ',
			SymbolKind::ClassLike => '',
		};
		$names = array_map(
			fn(UseItemNode $item) => substr($item->name->text, strlen($namespace) + 1)
				. ($item->alias === null ? '' : ' as ' . $item->alias->text),
			$stmt->items->getItems(),
		);
		$group = (new Parser)->parseStatement("use $type$namespace\\{" . implode(', ', $names) . '};');
		return $group instanceof UseNode ? $group : null;
	}
}
