<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{ImportStyle, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{FileNode, PlainNodeList, StatementNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count;


/**
 * How the names of the imports of each kind are put into `use` statements: `separated` gives every class, function or
 * constant a `use` of its own, `combined` puts all imports of the kind of a namespace into one comma-separated `use`,
 * and `grouped` lets a group use stay, `use A\{B, C};`. A list of the shapes lets each of them stay and writes
 * a statement in none of them in the first, or as imports of their own where that is `grouped`, which the rule never
 * writes: `[separated, grouped]` keeps a group use and splits a combined statement.
 *
 * A group use of a kind no group passes for is expanded into imports of their own, which a kind written combined then
 * joins. A statement with a comment keeps its imports where they would join another one, and a group use with
 * a comment inside is only reported where it would be expanded, the comment having no import of its own to go to.
 * The order of the imports is the matter of `ImportOrderRule`.
 */
#[RuleInfo(Stage::Structure, decisions: ImportStyle::Decisions)]
final class ImportNotationRule extends NodeRule
{
	/** the name of a kind => its plural in a message */
	private const Plurals = ['ClassLike' => 'classes', 'Function' => 'functions', 'Constant' => 'constants'];

	/**
	 * @var array<string, ?list<string>>  the name of a kind => the shapes that pass, the one written first; null where
	 * the imports stay as they are
	 */
	private array $shapes = [];


	public function configure(Values $values): void
	{
		foreach (ImportStyle::Kinds as $kind => $word) {
			$this->shapes[$kind] = $values->find("imports.grouping.$word")?->getWords();
		}
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
			} elseif (count($stmt->items) > 1 && !in_array('combined', $shapes, true)) {
				$this->split($stmt, $kind, $stmts, $context);
			} elseif ($shapes[0] === 'combined' && !in_array('separated', $shapes, true)) {
				$combined[$kind][] = $stmt;
			}
		}

		foreach ($combined as $kind => $uses) {
			$this->combine($uses, $kind, $context);
		}
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
			// and a leading backslash belongs to `qualification.uselessBackslash`
			foreach ($use->items->getItems() as $item) {
				$copy = $item->withoutEdgeTrivia();
				$first->items->append($copy);
			}

			$use->remove();
		}
	}


	private static function getDecision(string $kind): string
	{
		return 'imports.grouping.' . ImportStyle::Kinds[$kind];
	}
}
