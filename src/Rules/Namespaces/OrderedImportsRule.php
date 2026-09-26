<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, is_int, strlen;


/**
 * Consecutive `use` statements sorted by name: classes first, then functions, then constants. The names of
 * the whole block are sorted together and poured back into statements of the original shape, so a statement
 * importing two names keeps importing two. A group use keeps the names it holds, sorted under its prefix, and
 * stands where the first of them belongs. A group use that writes a type per item stands for several kinds at
 * once and keeps the order of its names, taking its place by the first of them. A block with a comment inside
 * is left as it is.
 */
#[RuleInfo(
	'dresscode/ordered-imports',
	Stage::Structure,
	description: 'Sorts use statements alphabetically, classes before functions before constants',
)]
final class OrderedImportsRule extends NodeRule implements ConfigurableRule
{
	private const Types = ['', 'function', 'const'];
	private const Alphabetical = 'alphabetical';
	private const ByKind = 'byKind';

	private string $order = self::Alphabetical;
	private bool $caseSensitive = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'caseSensitive' => Expect::bool(false),
			'order' => Expect::anyOf(self::Alphabetical, self::ByKind)->default(self::Alphabetical)
				->description('byKind only puts classes before functions before constants and keeps the order within a kind'),
		]);
	}


	public function configure(array $options): void
	{
		$this->caseSensitive = $options['caseSensitive'];
		$this->order = $options['order'];
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
		if (self::hasComment($run)) {
			return;
		}

		$names = $shapes = array_fill_keys(self::Types, []);
		foreach ($run as $stmt) {
			$type = $stmt->type === null ? '' : strtolower($stmt->type->text);
			if (!$stmt->isGroup()) {
				$shapes[$type][] = count($stmt->items);
				foreach ($stmt->items->getItems() as $item) {
					$names[$type][] = trim((string) $item);
				}
			} else {
				$texts = $this->sortItems($stmt);
				$shapes[$type][] = [self::prefixOf($stmt) . self::nameOf($texts[0] ?? ''), self::renderGroup($stmt, $texts)];
			}
		}

		$statements = [];
		foreach (self::Types as $type) {
			$pool = $names[$type];
			$order = $shapes[$type];
			if ($this->order === self::Alphabetical) {
				usort($pool, $this->compare(...));
				$order = $this->arrange($order, $pool);
			}

			foreach ($order as $shape) {
				$statements[] = is_int($shape)
					? 'use ' . ($type === '' ? '' : "$type ") . implode(', ', array_splice($pool, 0, $shape)) . ';'
					: $shape[1];
			}
		}

		$parser = new Parser;
		foreach ($run as $i => $stmt) {
			if (self::describe($stmt) === $statements[$i]) {
				continue;
			}

			if (!$context->report($stmt, $this->order === self::Alphabetical ? 'Imports are not sorted' : 'Imports are not grouped by kind')) {
				return;
			}

			$stmt->replaceWith($parser->parseStatement($statements[$i]));
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
		$texts = array_map(fn($item) => trim((string) $item), $items);
		if ($this->order === self::Alphabetical && !array_any($items, fn($item) => $item->type !== null)) {
			$prefix = self::prefixOf($stmt);
			usort($texts, fn(string $a, string $b) => $this->compare($prefix . $a, $prefix . $b));
		}

		return $texts;
	}


	/** The name the item imports, the type a group writes in front of it left out of it. */
	private static function nameOf(string $item): string
	{
		return (string) preg_replace('~^(?:function|const)\s+~i', '', $item);
	}


	private static function describe(UseNode $stmt): string
	{
		$items = array_map(fn($item) => trim((string) $item), $stmt->items->getItems());
		return $stmt->isGroup()
			? self::renderGroup($stmt, $items)
			: 'use ' . ($stmt->type === null ? '' : strtolower($stmt->type->text) . ' ') . implode(', ', $items) . ';';
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
			. ($stmt->type === null ? '' : strtolower($stmt->type->text) . ' ')
			. self::prefixOf($stmt)
			. (string) $stmt->openBrace
			. $inner
			. (string) $stmt->closeBrace
			. ';';
	}


	/** The prefix the items of a group stand under, with the backslash that joins them to it. */
	private static function prefixOf(UseNode $stmt): string
	{
		return $stmt->prefix === null ? '' : trim((string) $stmt->prefix) . '\\';
	}


	/** @param list<UseNode> $run */
	private static function hasComment(array $run): bool
	{
		$first = $run[0]->getFirstToken();
		$last = $run[count($run) - 1]->getLastToken();
		for ($token = $first; $token !== null; $token = $token->getNext()) {
			foreach ([...($token === $first ? [] : $token->leadingTrivia), ...$token->trailingTrivia] as $trivia) {
				if ($trivia->isComment()) {
					return true;
				}
			}

			if ($token === $last) {
				break;
			}
		}

		return false;
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
