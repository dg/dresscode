<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function is_int;


/**
 * Blank lines before and after statements of the configured kinds, as a count or a range: at least one
 * before every `return`, none or one after an `if`. The first statement of a block never gets a blank line
 * before it and the last none after it, those belong to the braces; a declaration of a function or a class,
 * an import, a namespace and a declare have rules of their own and are left out on both sides.
 */
#[RuleInfo(
	'dresscode/statement-blank-lines',
	Stage::Formatting,
	description: 'Puts blank lines before and after statements of the configured kinds',
)]
final class StatementBlankLinesRule extends Rule implements ConfigurableRule
{
	private const Kinds = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];

	/** @var array<string, int|array{int, ?int}|null> */
	private array $before = ['return' => [1, null]];

	/** @var array<string, int|array{int, ?int}|null> */
	private array $after = [];


	public static function getOptionsSchema(): Schema
	{
		$counts = fn() => Expect::arrayOf(BlankLines::schema(null), Expect::anyOf(...self::Kinds));
		return Expect::structure([
			'before' => $counts()->default(['return' => [1, null]])
				->description('Blank lines before a statement of the kind, as a count or a range [min, max] with null for no bound; yield means a statement made of a yield expression'),
			'after' => $counts()->description('Blank lines after a statement of the kind, before the next statement'),
		]);
	}


	public function configure(array $options): void
	{
		$this->before = $options['before'];
		$this->after = $options['after'];
	}


	public function getVisitedTypes(): array
	{
		return [StatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$list = $node->parent;
		if (!$node instanceof StatementNode || !$list instanceof NodeList || self::isForeign($node)) {
			return;
		}

		$items = $list->getItems();
		$index = $list->indexOf($node);
		$previous = $items[$index - 1] ?? null;
		if (!$previous instanceof StatementNode || self::isForeign($previous)) {
			return;
		}

		$kind = self::kindOf($node);
		$previousKind = self::kindOf($previous);
		$counts = [];
		if ($previousKind !== null && ($this->after[$previousKind] ?? null) !== null) {
			$counts[] = $this->after[$previousKind];
		}

		if ($kind !== null && ($this->before[$kind] ?? null) !== null) {
			$counts[] = $this->before[$kind];
		}

		$count = self::intersect($counts);
		$token = $node->getFirstToken();
		if ($count === null || $token === null || $token->is(TokenKind::CloseTag) || !$token->startsLine()) {
			return;
		}

		$what = $kind !== null && isset($this->before[$kind]) ? "before the $kind" : "after the $previousKind";
		BlankLines::ensure($token, $count, $what, $context);
	}


	private static function kindOf(StatementNode $stmt): ?string
	{
		return match (true) {
			$stmt instanceof Statement\BreakNode => 'break',
			$stmt instanceof Statement\ContinueNode => 'continue',
			$stmt instanceof Statement\DoWhileNode => 'do',
			$stmt instanceof Statement\ForNode => 'for',
			$stmt instanceof Statement\ForeachNode => 'foreach',
			$stmt instanceof Statement\IfNode => 'if',
			$stmt instanceof Statement\ReturnNode => 'return',
			$stmt instanceof Statement\SwitchNode => 'switch',
			$stmt instanceof Statement\TryNode => 'try',
			$stmt instanceof Statement\WhileNode => 'while',
			$stmt instanceof Statement\ExpressionStatementNode => match (true) {
				$stmt->expr instanceof Expression\ThrowNode => 'throw',
				$stmt->expr instanceof Expression\YieldNode, $stmt->expr instanceof Expression\YieldFromNode => 'yield',
				default => null,
			},
			default => null,
		};
	}


	/** Statements whose blank lines other rules own. */
	private static function isForeign(StatementNode $stmt): bool
	{
		return $stmt instanceof Statement\FunctionNode
			|| $stmt instanceof ClassLikeNode
			|| $stmt instanceof Statement\UseNode
			|| $stmt instanceof Statement\GroupUseNode
			|| $stmt instanceof Statement\NamespaceNode
			|| $stmt instanceof Statement\DeclareNode
			|| $stmt instanceof Statement\InlineHtmlNode;
	}


	/**
	 * The range both counts allow; when they exclude each other, the one given last wins.
	 * @param list<int|array{int, ?int}> $counts
	 * @return int|array{int, ?int}|null
	 */
	private static function intersect(array $counts): int|array|null
	{
		$result = null;
		foreach ($counts as $count) {
			[$min, $max] = is_int($count) ? [$count, $count] : $count;
			if ($result !== null) {
				[$currentMin, $currentMax] = $result;
				$min = max($min, $currentMin);
				$max = $max === null ? $currentMax : ($currentMax === null ? $max : min($max, $currentMax));
				if ($max !== null && $min > $max) {
					[$min, $max] = is_int($count) ? [$count, $count] : $count;
				}
			}

			$result = [$min, $max];
		}

		return $result === null ? null : ($result[0] === $result[1] ? $result[0] : $result);
	}
}
