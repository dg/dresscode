<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, ImportStyle, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use DressCode\Rules\{CodeWriter, NodeHelpers};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{FileNode, PlainNodeList, StatementNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, strlen;


/**
 * The shape of the import statements of each kind: `separate` gives every class, function or constant a
 * `use` of its own, `combined` puts all imports of the kind of a namespace into one comma-separated `use`.
 * A statement with a comment keeps its imports where they would join another one, and a group use with a comment
 * inside is only reported where it would be expanded, the comment having no import of its own to go to.
 *
 * A group use is expanded into that shape, kept as it is, or made: with `imports.groupUse: required` the imports of
 * one namespace are written as a single group use once there are at least `imports.groupUseMinNames` of them,
 * `use A\B; use A\C;` becoming `use A\{B, C};`; where a group of that namespace already stands, the names join it,
 * and a second group of it joins the first. A group the namespace has fewer names for is written as imports of their
 * own, a group of a single name among them. A name of the global namespace has no namespace to stand under, a
 * statement whose names belong to several namespaces is none of one, and a kind written combined is never grouped,
 * a group being unable to live beside it. How wide a group may grow is the matter of `MultilineImportRule` and
 * the order of the imports of `ImportOrderRule`.
 */
#[RuleInfo(Stage::Structure, decisions: ImportStyle::Decisions)]
final class ImportNotationRule extends NodeRule
{
	private const GroupUseMinNames = 'imports.groupUseMinNames';

	/** the name of a kind => its plural in a message */
	private const Plurals = ['ClassLike' => 'classes', 'Function' => 'functions', 'Constant' => 'constants'];

	/** @var array<string, ?string>  the name of a kind => separate, combined, or null where the imports stay as they are */
	private array $shapes = [];

	/** forbidden, required, or null where a group use stays as it is */
	private ?string $groupUse = null;

	private int $groupFrom = 2;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::GroupUseMinNames, new Count(2, range: false), 'How many names of one namespace make a group use, a group of fewer written as imports of their own', parameter: true, default: 2),
		];
	}


	public function configure(Values $values): void
	{
		foreach (ImportStyle::Kinds as $kind => $word) {
			$this->shapes[$kind] = $values->find("imports.statement.$word")?->getWord();
		}

		$this->groupUse = $values->find(ImportStyle::GroupUse)?->getWord();
		$this->groupFrom = $values->get(self::GroupUseMinNames)->getCount()[0];
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
			if ($stmt instanceof UseNode && $stmt->isGroup() && $this->groupUse === 'forbidden' && $this->isChecked($stmt)) {
				$this->expand($stmt, $stmts, $context);
			}
		}

		$combined = [];
		foreach ($stmts->getItems() as $stmt) {
			if (!$stmt instanceof UseNode || $stmt->isGroup()) { // a group writes its items under a prefix, not one per statement
				continue;
			}

			$kind = $stmt->symbolKind->name;
			if ($this->shapes[$kind] === 'separate' && count($stmt->items) > 1) {
				$this->split($stmt, $kind, $stmts, $context);
			} elseif ($this->shapes[$kind] === 'combined') {
				$combined[$kind][] = $stmt;
			}
		}

		foreach ($combined as $kind => $uses) {
			$this->combine($uses, $kind, $context);
		}

		if ($this->groupUse === 'required') {
			$this->groupImports($stmts, $context);
		}
	}


	/** Whether a group use imports something of a checked kind. */
	private function isChecked(UseNode $node): bool
	{
		return array_any($node->items->getItems(), fn(UseItemNode $item) => $this->shapes[$item->symbolKind->name] !== null);
	}


	/**
	 * `use A\{B, C as D};` becomes `use A\B;` and `use A\C as D;`.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function expand(UseNode $node, PlainNodeList $list, RuleContext $context): void
	{
		if ($context->report($node, 'The group use must be written as separate imports.', decision: ImportStyle::GroupUse, fixable: !$node->hasInnerComment())) {
			NodeHelpers::expandGroup($node, $list, $context->style->lineEnding);
		}
	}


	/**
	 * `use A, B;` becomes `use A;` and `use B;`.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function split(UseNode $node, string $kind, PlainNodeList $list, RuleContext $context): void
	{
		if ($context->report($node, 'Expected one import per `use` statement, ' . count($node->items) . ' found.', decision: 'imports.statement.' . ImportStyle::Kinds[$kind], fixable: !$node->items->hasInnerComment())) {
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
			if (!$context->report($use, 'Expected all imports of ' . self::Plurals[$kind] . ' in one `use` statement.', decision: 'imports.statement.' . ImportStyle::Kinds[$kind])) {
				continue;
			}

			// a copy of the item, not addImport(): merging the statements must not rewrite how a name is written,
			// and a leading backslash belongs to `qualification.uselessBackslash`
			foreach ($use->items->getItems() as $item) {
				$copy = $item->withoutEdgeTrivia();
				$first->items->append($copy);
			}

			$use->remove();
		}
	}


	/**
	 * The imports of each namespace written as one group use, or as imports of their own where there are too few.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function groupImports(PlainNodeList $list, RuleContext $context): void
	{
		// the first group of a namespace and the statements whose names belong in it, a later group among them
		$found = [];
		foreach ($list->getItems() as $stmt) {
			$namespace = $stmt instanceof UseNode && !$stmt->hasInnerComment() && !$stmt->hasTrailingComment() ? self::findNamespace($stmt) : null;
			if ($namespace === null || $this->shapes[$stmt->symbolKind->name] === 'combined') {
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
				foreach ([$group, ...$others] as $stmt) {
					if ($stmt?->isGroup()) {
						$this->expandSmallGroup($namespace, $stmt, $list, $context);
					}
				}
			} elseif ($group === null ? count($others) > 1 : $others !== []) {
				$this->joinGroup($namespace, $group, $others, $context);
			}
		}
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
		$joined = [];
		foreach ($group === null ? array_slice($others, 1) : $others as $stmt) {
			if ($context->report($stmt, "Expected all imports from `$namespace` in one group use.", decision: ImportStyle::GroupUse)) {
				$joined[] = $stmt;
			}
		}

		if ($joined === []) {
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

			$stmt->remove();
		}
	}


	/**
	 * A group the namespace has too few names for is written as imports of their own.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function expandSmallGroup(string $namespace, UseNode $group, PlainNodeList $list, RuleContext $context): void
	{
		if ($context->report($group, "Expected separate imports instead of the group use, because it has too few imports from `$namespace`.", decision: ImportStyle::GroupUse)) {
			NodeHelpers::expandGroup($group, $list, $context->style->lineEnding);
		}
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
