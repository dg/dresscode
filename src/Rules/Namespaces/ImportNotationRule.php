<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{ImportStyle, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{FileNode, PlainNodeList, StatementNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count;


/**
 * The shape of the import statements of each kind: `separate` gives every class, function or constant a
 * `use` of its own, `combined` puts all imports of the kind of a namespace into one comma-separated `use`.
 * A group use is expanded into that shape, or kept as it is. A statement with a comment keeps its imports where they
 * would join another one, and a group use with a comment inside is only reported where it would be expanded, the
 * comment having no import of its own to go to. The order of the imports is the matter of `ImportOrderRule`.
 */
#[RuleInfo(Stage::Structure, decisions: ImportStyle::Decisions)]
final class ImportNotationRule extends NodeRule
{
	/** the name of a kind => its plural in a message */
	private const Plurals = ['ClassLike' => 'classes', 'Function' => 'functions', 'Constant' => 'constants'];

	/** @var array<string, ?string>  the name of a kind => separate, combined, or null where the imports stay as they are */
	private array $shapes = [];

	/** forbidden, or null where a group use stays as it is */
	private ?string $groupUse = null;


	public function configure(Values $values): void
	{
		foreach (ImportStyle::Kinds as $kind => $word) {
			$this->shapes[$kind] = $values->find("imports.$word")?->getWord();
		}

		$this->groupUse = $values->find(ImportStyle::GroupUse)?->getWord();
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
		if ($context->report($node, 'Expected one import per `use` statement, ' . count($node->items) . ' found.', decision: 'imports.' . ImportStyle::Kinds[$kind], fixable: !$node->items->hasInnerComment())) {
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
			if (!$context->report($use, 'Expected all imports of ' . self::Plurals[$kind] . ' in one `use` statement.', decision: 'imports.' . ImportStyle::Kinds[$kind])) {
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
}
