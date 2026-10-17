<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, DecisionKind, ImportStyle, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use DressCode\Rules\{CodeWriter, NodeHelpers};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{FileNode, PlainNodeList, StatementNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, strlen;


/**
 * How the names of the imports of each kind are put into `use` statements: `separated` gives every class, function or
 * constant a `use` of its own, `combined` puts all imports of the kind of a namespace into one comma-separated `use`,
 * and `grouped` writes the imports of one namespace as a single group use once there are at least
 * `imports.groupMinNames` of them, `use A\B; use A\C;` becoming `use A\{B, C};`. A list of the shapes lets each of them
 * stay and writes a statement in none of them in the first: `[separated, grouped]` keeps a group use and splits
 * a combined statement.
 *
 * A group use of a kind no group passes for is expanded into imports of their own, which a kind written combined then
 * joins. Where grouped is written, the names of a namespace join the group of it where one stands, a second group of
 * it joins the first, a combined statement of its names becomes the group, and the names a namespace has too few of
 * for a group are written in the shape the list goes on with, as imports of their own where it names none. A name of
 * the global namespace has no namespace to stand under, and a statement whose names belong to several namespaces is
 * none of one. A statement with a comment keeps its imports where they would join another one, and a group use with
 * a comment inside is only reported where it would be expanded, the comment having no import of its own to go to. How
 * wide a group may grow is the matter of `MultilineImportRule` and the order of the imports of `ImportOrderRule`.
 */
#[RuleInfo(Stage::Structure, decisions: ImportStyle::Decisions)]
final class ImportNotationRule extends NodeRule
{
	private const GroupMinNames = 'imports.groupMinNames';

	/** the name of a kind => its plural in a message */
	private const Plurals = ['ClassLike' => 'classes', 'Function' => 'functions', 'Constant' => 'constants'];

	/**
	 * @var array<string, ?list<string>>  the name of a kind => the shapes that pass, the one written first; null where
	 * the imports stay as they are
	 */
	private array $shapes = [];

	private int $groupFrom = 2;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::GroupMinNames,
				new Count(2, range: false),
				'How many names of one namespace make a group use where `grouped` is written, fewer written in the next shape of the list, as imports of their own where it names none',
				kind: DecisionKind::Parameter,
				default: 2,
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach (ImportStyle::Kinds as $kind => $word) {
			$this->shapes[$kind] = $values->find("imports.grouping.$word")?->getWords();
		}

		$this->groupFrom = $values->get(self::GroupMinNames)->getCount()[0];
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

		$stmts = $node->statements;
		foreach ($stmts->getItems() as $stmt) {
			$kind = $stmt instanceof UseNode && $stmt->isGroup() ? $this->findKindRefusingGroup($stmt) : null;
			if ($kind !== null) {
				$this->expand($stmt, $kind, $stmts, $context);
			}
		}

		$combined = [];
		foreach ($stmts->getItems() as $stmt) {
			if (!$stmt instanceof UseNode || $stmt->isGroup()) { // a group writes its items under a prefix, not one per statement
				continue;
			}

			$kind = $stmt->symbolKind->name;
			$shapes = $this->shapes[$kind];
			if ($shapes === null) {
				continue;
			} elseif (
				count($stmt->items) > 1
				&& !in_array('combined', $shapes, true)
				// the names of one namespace written grouped first are the business of the groups below
				&& ($shapes[0] !== 'grouped' || self::findNamespace($stmt) === null)
			) {
				$this->split($stmt, $kind, $stmts, $context);
			} elseif ($shapes[0] === 'combined' && !in_array('separated', $shapes, true)) {
				$combined[$kind][] = $stmt;
			}
		}

		foreach ($combined as $kind => $uses) {
			$this->combine($uses, $kind, $context);
		}

		$this->groupImports($stmts, $context);
	}


	/** The kind of a name of the group use for which no group passes; null where the group stays. */
	private function findKindRefusingGroup(UseNode $node): ?string
	{
		foreach ($node->items->getItems() as $item) {
			$shapes = $this->shapes[$item->symbolKind->name];
			if ($shapes !== null && !in_array('grouped', $shapes, true)) {
				return $item->symbolKind->name;
			}
		}

		return null;
	}


	/**
	 * `use A\{B, C as D};` becomes `use A\B;` and `use A\C as D;`.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function expand(UseNode $node, string $kind, PlainNodeList $list, RuleContext $context): void
	{
		if ($context->report(
			$node,
			'The group use must be written as separate imports.',
			decision: self::getDecision($kind),
			fixable: !$node->hasInnerComment(),
		)) {
			NodeHelpers::expandGroup($node, $list, $context->style->lineEnding);
		}
	}


	/**
	 * `use A, B;` becomes `use A;` and `use B;`.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function split(UseNode $node, string $kind, PlainNodeList $list, RuleContext $context): void
	{
		if ($context->report(
			$node,
			'Expected one import per `use` statement, ' . count($node->items) . ' found.',
			decision: self::getDecision($kind),
			fixable: !$node->items->hasInnerComment(),
		)) {
			NodeHelpers::splitItems($node, $list, 'items', $context->style->lineEnding);
		}
	}


	/**
	 * The imports of the later statements join the first one; a statement with a comment stays where it is.
	 * @param list<UseNode> $uses
	 */
	private function combine(array $uses, string $kind, RuleContext $context): void
	{
		$uses = array_values(array_filter($uses, fn(UseNode $use) => !$use->hasInnerComment() && !$use->hasTrailingComment()));
		$first = $uses[0] ?? null;
		if ($first === null) {
			return;
		}

		foreach (array_slice($uses, 1) as $use) {
			if (!$context->report(
				$use,
				'Expected all imports of ' . self::Plurals[$kind] . ' in one `use` statement.',
				decision: self::getDecision($kind),
			)) {
				continue;
			}

			// a copy of the item, not addImport(): merging the statements must not rewrite how a name is written,
			// and a leading backslash belongs to `imports.leadingBackslash`
			foreach ($use->items->getItems() as $item) {
				$copy = $item->withoutEdgeTrivia();
				$first->items->append($copy);
			}

			NodeHelpers::removeMergingGaps($use, $context->style->lineEnding);
		}
	}


	/**
	 * The imports of each namespace of a kind written grouped as one group use, or as imports of their own where there
	 * are too few; a statement in another shape that passes stays as it is.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function groupImports(PlainNodeList $list, RuleContext $context): void
	{
		// the first group of a namespace and the statements whose names belong in it, a later group among them
		$found = [];
		foreach ($list->getItems() as $stmt) {
			$namespace = $stmt instanceof UseNode && $this->isToGroup($stmt) && !$stmt->hasInnerComment() && !$stmt->hasTrailingComment()
				? self::findNamespace($stmt)
				: null;
			if ($namespace === null) {
				continue;
			}

			$key = $stmt->symbolKind->name . ' ' . $namespace;
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
			if ($names < $this->groupFrom) {
				// too few names for a group are written in the next shape of the list
				$kind = ($group ?? $others[0])->symbolKind->name;
				$next = array_values(array_diff($this->shapes[$kind] ?? [], ['grouped']))[0] ?? 'separated';
				foreach ([$group, ...$others] as $stmt) {
					if ($stmt?->isGroup()) {
						$this->expandSmallGroup($namespace, $stmt, $next, $list, $context);
					} elseif ($stmt !== null && count($stmt->items) > 1 && $next === 'separated') {
						$this->split($stmt, $kind, $list, $context);
					}
				}
			} elseif ($group === null ? count($others) > 1 || count($others[0]->items) > 1 : $others !== []) {
				$this->joinGroup($namespace, $group, $others, $context);
			}
		}
	}


	/** Whether the statement is of a kind written grouped, and a group or in no other shape that passes. */
	private function isToGroup(UseNode $stmt): bool
	{
		$shapes = $this->shapes[$stmt->symbolKind->name];
		return $shapes !== null && $shapes[0] === 'grouped'
			&& ($stmt->isGroup() || !in_array(count($stmt->items) > 1 ? 'combined' : 'separated', $shapes, true));
	}


	/**
	 * The namespace every name of the statement stands in; null where they stand in several of them, where one
	 * stands in the global namespace, and where a leading backslash makes the namespace a matter of another rule.
	 */
	private static function findNamespace(UseNode $stmt): ?string
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
	private function joinGroup(string $namespace, ?UseNode $group, array $others, RuleContext $context): void
	{
		$host = $group ?? $others[0];
		// a statement of several names becoming the group changes their shape, which it is asked about too
		$reshaped = $group === null && count($host->items) > 1;
		$hostAgrees = !$reshaped;
		$joined = [];
		foreach ($others as $stmt) {
			if (
				($stmt !== $host || $reshaped)
				&& $context->report(
					$stmt,
					"Expected all imports from `$namespace` in one group use.",
					decision: self::getDecision($stmt->symbolKind->name),
				)
			) {
				if ($stmt === $host) {
					$hostAgrees = true;
				} else {
					$joined[] = $stmt;
				}
			}
		}

		if (!$hostAgrees || ($joined === [] && !$reshaped)) {
			return;
		}

		if ($group === null) {
			$group = self::buildGroup($host, $namespace);
			$host->replaceWith($group);
		}

		foreach ($joined as $stmt) {
			foreach ($stmt->items->getItems() as $item) {
				$group->addImport($item->fullName, $item->alias?->text);
			}

			NodeHelpers::removeMergingGaps($stmt, $context->style->lineEnding);
		}
	}


	/**
	 * A group the namespace has too few names for is written as imports of their own, or as one `use` of all of them
	 * where the list goes on with combined.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function expandSmallGroup(string $namespace, UseNode $group, string $next, PlainNodeList $list, RuleContext $context): void
	{
		$combined = $next === 'combined';
		if (!$context->report(
			$group,
			($combined ? 'Expected one `use` statement' : 'Expected separate imports')
			. " instead of the group use, because it has too few imports from `$namespace`.",
			decision: self::getDecision($group->symbolKind->name),
		)) {
			return;
		} elseif (!$combined) {
			NodeHelpers::expandGroup($group, $list, $context->style->lineEnding);
			return;
		}

		$names = array_map(
			fn(UseItemNode $item) => $item->fullName . ($item->alias === null ? '' : ' as ' . $item->alias->text),
			$group->items->getItems(),
		);
		$group->replaceWith(
			(new Builder)->fragment(UseNode::class, 'use ' . CodeWriter::spellImportKind($group->symbolKind) . implode(', ', $names) . ';'),
		);
	}


	private static function getDecision(string $kind): string
	{
		return 'imports.grouping.' . ImportStyle::Kinds[$kind];
	}


	/** The statement written as a group of the names it imports, which the names of the others then join. */
	private static function buildGroup(UseNode $stmt, string $namespace): UseNode
	{
		$type = CodeWriter::spellImportKind($stmt->symbolKind);
		$names = array_map(
			fn(UseItemNode $item) => substr($item->name->text, strlen($namespace) + 1)
				. ($item->alias === null ? '' : ' as ' . $item->alias->text),
			$stmt->items->getItems(),
		);
		return (new Builder)->fragment(UseNode::class, "use $type$namespace\\{" . implode(', ', $names) . '};');
	}
}
