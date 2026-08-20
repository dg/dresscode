<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Rules\BlankLines;
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
final class StatementBlankLinesRule extends GapRule implements ConfigurableRule
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


	public function getClaims(): array
	{
		return ['*' => ['statements:item' => [
			fn(Gap $gap) => $this->claim($gap->value, ($gap->index ?? 0) - 1, $this->before),
			fn(Gap $gap) => $this->claim($gap->value, ($gap->index ?? 0) + 1, $this->after),
		]]];
	}


	/**
	 * The count the statement's kind asks for on the side of its neighbor at the index, when that is a statement
	 * of its own standing and not a declaration with a rule of its own.
	 * @param array<string, int|array{int, ?int}|null> $counts
	 */
	private function claim(Node|Token $stmt, int $neighbor, array $counts): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof StatementNode || !$list instanceof NodeList || self::isForeign($stmt)) {
			return null;
		}

		$other = $list->getItems()[$neighbor] ?? null;
		$count = $other instanceof StatementNode && !self::isForeign($other) ? $counts[self::kindOf($stmt)] ?? null : null;
		return $count === null ? null : Claim::blank($count);
	}


	private static function kindOf(StatementNode $stmt): string
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
				$stmt->expression instanceof Expression\ThrowNode => 'throw',
				$stmt->expression instanceof Expression\YieldNode, $stmt->expression instanceof Expression\YieldFromNode => 'yield',
				default => '',
			},
			default => '',
		};
	}


	/** Statements whose blank lines other rules own. */
	private static function isForeign(StatementNode $stmt): bool
	{
		return $stmt instanceof Statement\FunctionNode
			|| $stmt instanceof ClassLikeNode
			|| $stmt instanceof Statement\UseNode
			|| $stmt instanceof Statement\NamespaceNode
			|| $stmt instanceof Statement\DeclareNode
			|| $stmt instanceof Statement\InlineHtmlNode;
	}
}
