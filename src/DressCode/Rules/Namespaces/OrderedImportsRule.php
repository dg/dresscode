<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\GroupUseNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * Consecutive use statements sorted by name: classes first, then functions, then constants. The names of
 * the whole block are sorted together and poured back into statements of the original shape, so a statement
 * importing two names keeps importing two. A block with a comment inside is left as it is.
 */
#[RuleInfo(
	'dresscode/ordered-imports',
	Stage::Structure,
	description: 'Sorts use statements alphabetically, classes before functions before constants',
)]
final class OrderedImportsRule extends Rule implements ConfigurableRule
{
	private const Types = ['', 'function', 'const'];

	private bool $caseSensitive = false;
	private bool $alphabetically = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'caseSensitive' => Expect::bool(false),
			'alphabetically' => Expect::bool(true)->description('False only puts classes before functions before constants and keeps the order within a kind'),
		]);
	}


	public function configure(array $options): void
	{
		$this->caseSensitive = $options['caseSensitive'];
		$this->alphabetically = $options['alphabetically'];
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
		foreach ([...$node->stmts->getItems(), null] as $stmt) {
			if ($stmt instanceof UseNode || $stmt instanceof GroupUseNode) {
				$run[] = $stmt;
			} elseif ($run) {
				$this->sortRun($run, $context);
				$run = [];
			}
		}
	}


	/**
	 * @param list<UseNode|GroupUseNode> $run
	 */
	private function sortRun(array $run, RuleContext $context): void
	{
		if (count($run) < 2 && !$run[0] instanceof UseNode || self::hasComment($run)) {
			return;
		}

		$names = $slots = array_fill_keys(self::Types, []);
		foreach ($run as $stmt) {
			if (!$stmt instanceof UseNode) {
				return;
			}

			$type = $stmt->type === null ? '' : strtolower($stmt->type->text);
			$slots[$type][] = count($stmt->items);
			foreach ($stmt->items->getItems() as $item) {
				$names[$type][] = trim((string) $item);
			}
		}

		$statements = [];
		foreach (self::Types as $type) {
			$pool = $names[$type];
			if ($this->alphabetically) {
				usort($pool, $this->compare(...));
			}

			foreach ($slots[$type] as $size) {
				$statements[] = 'use ' . ($type === '' ? '' : "$type ") . implode(', ', array_splice($pool, 0, $size)) . ';';
			}
		}

		$parser = new Parser;
		foreach ($run as $i => $stmt) {
			if (self::describe($stmt) === $statements[$i]) {
				continue;
			}

			if (!$context->report($stmt, $this->alphabetically ? 'Imports are not sorted' : 'Imports are not grouped by kind')) {
				return;
			}

			$stmt->replaceWith($parser->parseStatement($statements[$i]));
		}
	}


	private static function describe(UseNode $stmt): string
	{
		$items = array_map(fn($item) => trim((string) $item), $stmt->items->getItems());
		return 'use ' . ($stmt->type === null ? '' : strtolower($stmt->type->text) . ' ') . implode(', ', $items) . ';';
	}


	/** @param list<UseNode|GroupUseNode> $run */
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
