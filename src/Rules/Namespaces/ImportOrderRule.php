<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, DecisionKind, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\{Flag, Words};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, is_int, strlen;


/**
 * Consecutive `use` statements sorted by name: classes first, then functions, then constants. The names of
 * the whole block are sorted together and poured back into statements of the original shape, so a statement
 * importing two names keeps importing two. A group use keeps the names it holds, sorted under its prefix, and
 * stands where the first of them belongs. A group use that writes a type per item stands for several kinds at
 * once and keeps the order of its names, taking its place by the first of them. A block with a comment inside
 * is left as it is. Grouped by kind only, the names of a kind keep the order written.
 */
#[RuleInfo(Stage::Structure)]
final class ImportOrderRule extends NodeRule
{
	private const Types = ['', 'function', 'const'];
	private const Order = 'imports.order.withinKind';
	private const CaseSensitive = 'imports.order.caseSensitive';

	/** whether the names of a kind are sorted, not only the kinds */
	private bool $sortNames = true;
	private bool $caseSensitive = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Order, new Words([
				'alphabetical' => 'the names of a kind sorted alphabetically',
				'asWritten' => 'the names of a kind in the order written',
			]), 'The order of the names of one kind among consecutive imports, classes standing before functions and functions before constants, the names of a statement poured back into statements of the shapes written'),
			new Decision(self::CaseSensitive, new Flag, 'Whether `Acme` sorts before `acme` rather than with it', kind: DecisionKind::Parameter, default: false),
		];
	}


	public function configure(Values $values): void
	{
		$this->sortNames = $values->get(self::Order)->getWord() === 'alphabetical';
		$this->caseSensitive = $values->get(self::CaseSensitive)->getFlag();
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

		$run = [];
		foreach ([...$node->statements->getItems(), null] as $stmt) {
			if ($stmt instanceof UseNode) {
				$run[] = $stmt;
			} elseif ($run) {
				$this->sortRun($run, $context);
				$run = [];
			}
		}
	}


	/**
	 * @param list<UseNode> $run
	 */
	private function sortRun(array $run, RuleContext $context): void
	{
		$names = $shapes = array_fill_keys(self::Types, []);
		foreach ($run as $stmt) {
			$type = $stmt->kindKeyword === null ? '' : strtolower($stmt->kindKeyword->text);
			if (!$stmt->isGroup()) {
				$shapes[$type][] = count($stmt->items);
				foreach ($stmt->items->getItems() as $item) {
					$names[$type][] = $item->text;
				}
			} else {
				$texts = $this->sortItems($stmt);
				$shapes[$type][] = [self::readPrefix($stmt) . self::stripKind($texts[0] ?? ''), self::renderGroup($stmt, $texts)];
			}
		}

		$statements = [];
		foreach (self::Types as $type) {
			$pool = $names[$type];
			$order = $shapes[$type];
			if ($this->sortNames) {
				usort($pool, $this->compare(...));
				$order = $this->arrange($order, $pool);
			}

			foreach ($order as $shape) {
				$statements[] = is_int($shape)
					? 'use ' . ($type === '' ? '' : "$type ") . implode(', ', array_splice($pool, 0, $shape)) . ';'
					: $shape[1];
			}
		}

		$changed = array_filter($run, fn(UseNode $stmt, int $i) => self::writeCanonically($stmt) !== $statements[$i], ARRAY_FILTER_USE_BOTH);
		$last = $run[count($run) - 1]->getLastToken();
		if ($changed === [] || $run[0]->getFirstToken()->hasCommentUpTo($last) || $last->hasTrailingComment()) {
			return;
		}

		$builder = new Builder;
		foreach ($changed as $i => $stmt) {
			$expected = str_contains($statements[$i], "\n") ? (string) preg_replace('~\{.*\}~s', '{…}', $statements[$i]) : $statements[$i];
			if (!$context->report($stmt, 'Expected ' . Violation::formatCode($expected) . ' here, as the imports are ' . ($this->sortNames ? 'sorted by name.' : 'grouped by kind.'))) {
				return;
			}

			$stmt->replaceWith($builder->statement($statements[$i]));
		}
	}


	/**
	 * The shapes in the order the sorted names put them: the plain statements keep their shapes and their
	 * order and take the names in turn, and a group stands where the first name it imports belongs.
	 * @param  list<int|array{string, string}>  $shapes  the size of a plain statement, or the name a group sorts by with its text
	 * @param list<string> $pool
	 * @return list<int|array{string, string}>
	 */
	private function arrange(array $shapes, array $pool): array
	{
		$groups = array_values(array_filter($shapes, fn($shape) => !is_int($shape)));
		usort($groups, fn(array $a, array $b) => $this->compare($a[0], $b[0]));

		$result = [];
		$taken = 0;
		foreach (array_filter($shapes, is_int(...)) as $size) {
			while ($groups !== [] && $this->compare($groups[0][0], $pool[$taken]) < 0) {
				$result[] = array_shift($groups);
			}

			$result[] = $size;
			$taken += $size;
		}

		return [...$result, ...$groups];
	}


	/**
	 * The items of the group in the order the sorting puts them, each as it is written under the prefix;
	 * a group writing a type per item stands for several kinds at once, of whose order the rule has no opinion,
	 * and keeps the order it has.
	 * @return list<string>
	 */
	private function sortItems(UseNode $stmt): array
	{
		$items = $stmt->items->getItems();
		$texts = array_map(fn($item) => $item->text, $items);
		if ($this->sortNames && !array_any($items, fn($item) => $item->kindKeyword !== null)) {
			$prefix = self::readPrefix($stmt);
			usort($texts, fn(string $a, string $b) => $this->compare($prefix . $a, $prefix . $b));
		}

		return $texts;
	}


	/** The name the item imports, the type a group writes in front of it left out of it. */
	private static function stripKind(string $item): string
	{
		return (string) preg_replace('~^(?:function|const)\s+~i', '', $item);
	}


	private static function writeCanonically(UseNode $stmt): string
	{
		$items = array_map(fn($item) => $item->text, $stmt->items->getItems());
		return $stmt->isGroup()
			? self::renderGroup($stmt, $items)
			: 'use ' . ($stmt->kindKeyword === null ? '' : strtolower($stmt->kindKeyword->text) . ' ') . implode(', ', $items) . ';';
	}


	/**
	 * The group with the items written in the given order, keeping the whitespace it has place by place,
	 * so that one spread over lines stays that way.
	 * @param list<string> $texts
	 */
	private static function renderGroup(UseNode $stmt, array $texts): string
	{
		$separators = $stmt->items->getSeparators();
		$inner = '';
		foreach ($stmt->items->getItems() as $i => $item) {
			$whole = (string) $item;
			$inner .= substr($whole, 0, strlen($whole) - strlen(ltrim($whole)))
				. $texts[$i]
				. substr($whole, strlen(rtrim($whole)))
				. (string) ($separators[$i] ?? '');
		}

		return 'use '
			. ($stmt->kindKeyword === null ? '' : strtolower($stmt->kindKeyword->text) . ' ')
			. self::readPrefix($stmt)
			. (string) $stmt->openBrace
			. $inner
			. (string) $stmt->closeBrace
			. ';';
	}


	/** The prefix the items of a group stand under, with the backslash that joins them to it. */
	private static function readPrefix(UseNode $stmt): string
	{
		return $stmt->prefix === null ? '' : $stmt->prefix->text . '\\';
	}


	/**
	 * A backslash sorts before any character of a name, so a namespace precedes its sub-namespaces.
	 */
	private function compare(string $a, string $b): int
	{
		$a = strtr($a, ['\\' => ' ']);
		$b = strtr($b, ['\\' => ' ']);
		return $this->caseSensitive ? strcmp($a, $b) : strcasecmp($a, $b);
	}
}
