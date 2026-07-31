<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{FileNode, PlainNodeList, StatementNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function array_slice, count;


/**
 * The shape of the import statements of each kind: `single` gives every class, function or constant a
 * `use` of its own, `combined` puts all imports of the kind of a namespace into one comma-separated `use`.
 * A group use is expanded into that shape, or kept as it is. A statement with a comment keeps its imports where they
 * would join another one. The order of the imports is the matter of dresscode/orderedImports.
 */
#[RuleInfo(
	'dresscode/importNotation',
	Stage::Structure,
	description: 'Writes the imports of each kind one per use statement or all in one, and expands group use declarations',
)]
final class ImportNotationRule extends NodeRule implements ConfigurableRule
{
	/** kind => its plural in a message */
	private const Kinds = ['class' => 'classes', 'function' => 'functions', 'constant' => 'constants'];

	/** @var array<string, ?string>  kind => single, combined or null */
	private array $shapes = ['class' => 'single', 'function' => 'single', 'constant' => 'single'];
	private string $group = 'expand';


	public static function getOptionsSchema(): Schema
	{
		$shape = Expect::anyOf('single', 'combined', 'keep')->default('single');
		return Expect::structure([
			'class' => (clone $shape)->description('`single` gives every class its own import, `combined` puts all classes of the namespace into one; `keep` leaves them alone'),
			'function' => clone $shape,
			'constant' => clone $shape,
			'group' => Expect::anyOf('expand', 'keep')->default('expand')
				->description('`expand` turns a group use of a checked kind into the shape of that kind, `keep` leaves it as it is'),
		]);
	}


	public function configure(array $options): void
	{
		foreach (array_keys(self::Kinds) as $kind) {
			$this->shapes[$kind] = $options[$kind] === 'keep' ? null : $options[$kind];
		}

		$this->group = $options['group'];
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

		$stmts = $node->statements;
		foreach ($stmts->getItems() as $stmt) {
			if ($stmt instanceof UseNode && $stmt->isGroup() && $this->group === 'expand' && $this->isChecked($stmt)) {
				$this->expand($stmt, $stmts, $context);
			}
		}

		$combined = [];
		foreach ($stmts->getItems() as $stmt) {
			if (!$stmt instanceof UseNode || $stmt->isGroup()) { // a group writes its items under a prefix, not one per statement
				continue;
			}

			$kind = self::kindOf($stmt->symbolKind);
			if ($this->shapes[$kind] === 'single' && count($stmt->items) > 1) {
				$this->split($stmt, $stmts, $context);
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
		return array_any($node->items->getItems(), fn(UseItemNode $item) => $this->shapes[self::kindOf($item->symbolKind)] !== null);
	}


	private static function kindOf(SymbolKind $kind): string
	{
		return match ($kind) {
			SymbolKind::Function => 'function',
			SymbolKind::Constant => 'constant',
			SymbolKind::ClassLike => 'class',
		};
	}


	/**
	 * `use A\{B, C as D};` becomes `use A\B;` and `use A\C as D;`.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function expand(UseNode $node, PlainNodeList $list, RuleContext $context): void
	{
		if ($context->report($node, 'A group use must be written as imports of their own')) {
			NodeHelpers::expandGroup($node, $list, $context->style->lineEnding);
		}
	}


	/**
	 * `use A, B;` becomes `use A;` and `use B;`.
	 * @param PlainNodeList<StatementNode> $list
	 */
	private function split(UseNode $node, PlainNodeList $list, RuleContext $context): void
	{
		if (!$context->report($node, 'One import per use statement')) {
			return;
		}

		NodeHelpers::splitItems($node, $list, 'items', $context->style->lineEnding);
	}


	/**
	 * The imports of the later statements join the first one; a statement with a comment stays where it is.
	 * @param list<UseNode> $uses
	 */
	private function combine(array $uses, string $kind, RuleContext $context): void
	{
		$uses = array_values(array_filter($uses, fn(UseNode $use) => !NodeHelpers::hasCommentUpToLineEnding($use)));
		$first = $uses[0] ?? null;
		if ($first === null) {
			return;
		}

		foreach (array_slice($uses, 1) as $use) {
			if (!$context->report($use, 'All imports of ' . self::Kinds[$kind] . ' in one use statement')) {
				continue;
			}

			// a clone, not addImport(): merging the statements must not rewrite how a name is written,
			// and a leading backslash belongs to dresscode/uselessImportBackslash
			foreach ($use->items->getItems() as $item) {
				$copy = $item->withoutEdgeTrivia();
				$first->items->append($copy);
			}

			$use->remove();
		}
	}
}
