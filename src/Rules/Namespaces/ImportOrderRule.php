<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, DecisionKind, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\{Flag, Names, Words};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, is_int, strlen;


/**
 * Consecutive `use` statements sorted by kind, classes, functions and constants in the order `imports.order.kinds`
 * gives, and by name. The names of a kind are sorted together and poured back into statements of the original shape,
 * so a statement importing two names keeps importing two. A group use keeps the names it holds, sorted under its
 * prefix, and stands where the first of them belongs. A group use that writes a type per item stands for several
 * kinds at once and keeps the order of its names, taking its place by the first of them. A block with a comment
 * inside is left as it is. Sorted by kind only, the names of a kind keep the order written; sorted by name only,
 * each run of statements of one kind is sorted where it stands.
 */
#[RuleInfo(Stage::Structure)]
final class ImportOrderRule extends NodeRule
{
	private const Types = ['', 'function', 'const'];

	/** the word of a kind in the decision => the keyword a statement of it is written with */
	private const KindTypes = ['class' => '', 'function' => 'function', 'constant' => 'const'];
	private const Kinds = 'imports.order.kinds';
	private const Names = 'imports.order.names';
	private const CaseSensitive = 'imports.order.caseSensitive';

	/** @var ?list<string>  the keywords of the kinds in the order they stand; null where each run of a kind stays where it is */
	private ?array $types = self::Types;

	/** whether the names of a kind are sorted */
	private bool $sortNames = true;
	private bool $caseSensitive = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Kinds, new Names([
				'class' => 'the imports of classes',
				'function' => 'the imports of functions',
				'constant' => 'the imports of constants',
			], ordered: true), 'The order of the kinds among consecutive imports, a kind not listed following in the order classes, functions, constants'),
			new Decision(self::Names, new Words([
				'alphabetical' => 'the names of a kind sorted alphabetically',
			]), 'The order of the names of one kind among consecutive imports, the names of a statement poured back into statements of the shapes written'),
			new Decision(
				self::CaseSensitive,
				new Flag,
				'Whether `Acme` sorts before `acme` rather than with it',
				kind: DecisionKind::Parameter,
				default: false,
			),
		];
	}


	public function configure(Values $values): void
	{
		$kinds = $values->find(self::Kinds)?->getNames();
		$this->types = $kinds === null
			? null
			: array_values(array_unique([...array_map(fn(string $kind) => self::KindTypes[$kind], $kinds), ...self::Types]));
		$this->sortNames = $values->find(self::Names) !== null;
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
		$current = array_map(self::spellCanonically(...), $run);
		if ($this->types === null) {
			// each run of statements of one kind is sorted where it stands
			$statements = $segment = [];
			foreach ($run as $i => $stmt) {
				$segment[] = $stmt;
				if (!isset($run[$i + 1]) || self::getType($run[$i + 1]) !== self::getType($stmt)) {
					$statements = [...$statements, ...$this->arrangeStatements($segment, [self::getType($stmt)], $this->sortNames)];
					$segment = [];
				}
			}
		} else {
			$statements = $this->arrangeStatements($run, $this->types, $this->sortNames);
		}

		$changed = array_filter($run, fn(int $i) => $current[$i] !== $statements[$i], ARRAY_FILTER_USE_KEY);
		$last = $run[count($run) - 1]->getLastToken();
		if ($changed === [] || $run[0]->getFirstToken()->hasCommentUpTo($last) || $last->hasTrailingComment()) {
			return;
		}

		$byKind = $this->types !== null && $this->arrangeStatements($run, $this->types, sortNames: false) !== $current;
		$builder = new Builder;
		foreach ($changed as $i => $stmt) {
			$expected = str_contains($statements[$i], "\n") ? (string) preg_replace('~\{.*\}~s', '{…}', $statements[$i]) : $statements[$i];
			if (!$context->report(
				$stmt,
				'Expected '
				. Violation::formatCode($expected)
				. ' here, because the imports are '
				. ($byKind ? 'grouped by kind.' : 'sorted by name.'),
				decision: $byKind ? self::Kinds : self::Names,
			)) {
				return;
			}

			$stmt->replaceWith($builder->statement($statements[$i]));
		}
	}


	/**
	 * The statements of the run in the order of the kinds, the names of a kind sorted where they are, each written
	 * canonically.
	 * @param list<UseNode> $run
	 * @param list<string> $types
	 * @return list<string>
	 */
	private function arrangeStatements(array $run, array $types, bool $sortNames): array
	{
		$names = $shapes = array_fill_keys(self::Types, []);
		foreach ($run as $stmt) {
			$type = self::getType($stmt);
			if (!$stmt->isGroup()) {
				$shapes[$type][] = count($stmt->items);
				foreach ($stmt->items->getItems() as $item) {
					$names[$type][] = $item->text;
				}
			} else {
				$texts = $this->sortItems($stmt, $sortNames);
				$shapes[$type][] = [self::readPrefix($stmt) . self::stripKind($texts[0] ?? ''), self::renderGroup($stmt, $texts)];
			}
		}

		$statements = [];
		foreach ($types as $type) {
			$pool = $names[$type];
			$order = $shapes[$type];
			if ($sortNames) {
				usort($pool, $this->compare(...));
				$order = $this->arrange($order, $pool);
			}

			foreach ($order as $shape) {
				$statements[] = is_int($shape)
					? 'use ' . ($type === '' ? '' : "$type ") . implode(', ', array_splice($pool, 0, $shape)) . ';'
					: $shape[1];
			}
		}

		return $statements;
	}


	/** The keyword the statement imports its kind with, `function` or `const`, empty for classes. */
	private static function getType(UseNode $stmt): string
	{
		return $stmt->kindKeyword === null ? '' : strtolower($stmt->kindKeyword->text);
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
	private function sortItems(UseNode $stmt, bool $sortNames): array
	{
		$items = $stmt->items->getItems();
		$texts = array_map(fn($item) => $item->text, $items);
		if ($sortNames && !array_any($items, fn($item) => $item->kindKeyword !== null)) {
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


	private static function spellCanonically(UseNode $stmt): string
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
