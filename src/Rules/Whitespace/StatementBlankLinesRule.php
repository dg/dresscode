<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Map;
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{ClassLikeNode, Expression, FileNode, PlainNodeList, Statement, StatementNode};
use function count, is_int;


/**
 * How many blank lines stand between statements: in the header of a file, around declarations among statements, and
 * before and after a statement of a kind. Comments stay with the code below the blank lines, and a doc comment counts
 * as part of what it documents.
 */
#[RuleInfo(Stage::Formatting, decisions: ['blankLines.afterPhpdoc'])]
final class StatementBlankLinesRule extends GapRule
{
	private const Kinds = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];
	private const Counts = [
		'afterOpeningTag' => 'After the line of `<?php`, which then carries no code; a file with markup outside PHP keeps its tag',
		'beforeNamespace' => 'Before the namespace declaration and, where a doc comment of the file stands above it, also between the two',
		'afterNamespace' => 'After an unbraced namespace declaration',
		'afterImports' => 'After the last import, before the rest of the code',
		'betweenImportKinds' => 'Between the imports of classes, functions and constants; imports of one kind never have a blank line between them',
		'beforeDeclaration' => 'Before a class, interface, trait, enum or function that follows the namespace or the imports, in place of `afterNamespace` and `afterImports`',
		'betweenDeclarations' => 'Before and after a class, interface, trait, enum or function declared among statements, where the header above it does not decide',
		'afterPhpdoc' => null,
	];

	/** @var array<string, int|array{int, ?int}|null>  the decision of a count => its count, null where it is kept */
	private array $counts = [];

	/** @var array<string, int|array{int, ?int}>  kind => count, a kind left as it is not being there */
	private array $beforeStatement = ['return' => [1, null]];

	/** @var array<string, int|array{int, ?int}> */
	private array $afterStatement = [];

	private readonly BlankLineClaims $claims;


	public function __construct()
	{
		$this->claims = new BlankLineClaims;
	}


	public static function getDecisions(): array
	{
		$count = Domain::blankLines();
		$kinds = new Map($count, wildcards: false, words: array_fill_keys(self::Kinds, ''));
		$decisions = [];
		foreach (array_filter(self::Counts) as $key => $description) {
			$decisions[] = new Decision("blankLines.$key", $count, $description);
		}

		$decisions[] = new Decision('blankLines.beforeStatement', $kinds, 'Before a statement of the kind, `yield` meaning a statement made of a `yield` expression');
		$decisions[] = new Decision('blankLines.afterStatement', $kinds, 'After a statement of the kind, before the next statement');
		return $decisions;
	}


	public function configure(Values $values): void
	{
		foreach (array_keys(self::Counts) as $key) {
			$this->counts[$key] = BlankLineClaims::readCount($values->get("blankLines.$key"));
		}

		$readKinds = fn(string $key) => $values->isKept("blankLines.$key")
			? []
			: array_filter(array_map(BlankLineClaims::readCount(...), $values->get("blankLines.$key")->getEntries()), fn($count) => $count !== null);
		$this->beforeStatement = $readKinds('beforeStatement');
		$this->afterStatement = $readKinds('afterStatement');
	}


	public function getClaims(): array
	{
		$header = ['statements:item' => [fn(Gap $gap) => $this->claimInHeader($gap->value, $gap->index ?? 0), null]];
		return [
			FileNode::class => $header,
			Statement\NamespaceNode::class => $header,
			'*' => [
				// a statement is asked about only where some decision can give it a count
				'statements:item' => [
					$this->counts['betweenDeclarations'] !== null || $this->counts['afterPhpdoc'] !== null || $this->beforeStatement !== []
						? fn(Gap $gap) => $this->claimBeforeStatement($gap->value, $gap->index ?? 0)
						: null,
					$this->counts['betweenDeclarations'] !== null || $this->afterStatement !== []
						? fn(Gap $gap) => $this->claimAfterStatement($gap->value, $gap->index ?? 0)
						: null,
				],
				'attributes' => [null, $this->claims->claim($this->counts['afterPhpdoc'], 'afterPhpdoc')],
			],
		];
	}


	/**
	 * What the header asks for above the statement: the line of the opening tag above the first, the namespace
	 * above its statements, the imports above what follows them, all of it where the statement follows
	 * several of these. A gap where several of them meet is reported under the most specific one, the last.
	 */
	private function claimInHeader(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof StatementNode || !$list instanceof PlainNodeList) {
			return null;
		}

		$owner = $list->parent;
		$previous = $list->getItems()[$index - 1] ?? null;
		$counts = [];
		$line = $below = null;
		$key = 'afterOpeningTag';
		if (
			$owner instanceof FileNode
			&& $stmt === self::findFirstCode($owner)
			&& $this->counts['afterOpeningTag'] !== null
			&& self::isMonolithic($owner)
		) {
			$counts[] = $this->counts['afterOpeningTag'];
			$line = Line::Next; // the tag ends its line before the blank lines after it can be counted
		}

		if ($stmt instanceof Statement\NamespaceNode && $this->counts['beforeNamespace'] !== null) {
			$counts[] = $this->counts['beforeNamespace'];
			$key = 'beforeNamespace';
			// a doc comment above the namespace is the docblock of the file, a block of the header of its own
			$comments = $stmt->getLeadingComments();
			$below = $comments !== [] && $comments[count($comments) - 1]->is(Trivia::DocComment) ? $this->counts['beforeNamespace'] : null;
		}

		$declared = $stmt instanceof ClassLikeNode || $stmt instanceof Statement\FunctionNode ? $this->counts['beforeDeclaration'] : null;
		if ($owner instanceof Statement\NamespaceNode && $owner->semicolon !== null && $index === 0) {
			$counts[] = $declared ?? $this->counts['afterNamespace'];
			$key = $declared === null ? 'afterNamespace' : 'beforeDeclaration';
		}

		if ($previous instanceof Statement\UseNode) {
			[$counts[], $key] = match (true) {
				!$stmt instanceof Statement\UseNode => $declared === null ? [$this->counts['afterImports'], 'afterImports'] : [$declared, 'beforeDeclaration'],
				$previous->symbolKind === $stmt->symbolKind => [$this->counts['betweenImportKinds'] === null ? null : 0, 'betweenImportKinds'],
				default => [$this->counts['betweenImportKinds'], 'betweenImportKinds'],
			};
		}

		$count = self::intersect(array_values(array_filter($counts, fn($count) => $count !== null)));
		return $this->claims->claim($count, $key, $below, 'beforeNamespace', $line);
	}


	/**
	 * The range all the counts allow; when two exclude each other, the one given last wins.
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


	/**
	 * Before a declaration among statements and below its doc comment, else what the kind of the statement
	 * asks for; the two never answer for the same statement.
	 */
	private function claimBeforeStatement(Node|Token $stmt, int $index): ?Claim
	{
		$declaration = $stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode ? $stmt : null;
		$blank = $declaration !== null && $index > 0 ? $this->counts['betweenDeclarations'] : null;
		$below = $declaration === null ? null : BlankLineClaims::findBelowDocComment($declaration, $this->counts['afterPhpdoc']);
		return $blank === null && $below === null
			? $this->claimByKind($stmt, $index - 1, $this->beforeStatement, 'beforeStatement')
			: $this->claims->claim($blank, 'betweenDeclarations', $below, 'afterPhpdoc');
	}


	/** After a declaration among statements, before what is not one, else what the kind of the statement asks for. */
	private function claimAfterStatement(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if (($stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode) && $list instanceof PlainNodeList) {
			$next = $list->getItems()[$index + 1] ?? null;
			return $next !== null
				&& !$next instanceof Statement\FunctionNode
				&& !$next instanceof ClassLikeNode
					? $this->claims->claim($this->counts['betweenDeclarations'], 'betweenDeclarations')
					: null;
		}

		return $this->claimByKind($stmt, $index + 1, $this->afterStatement, 'afterStatement');
	}


	/**
	 * The count the statement's kind asks for on the side of its neighbor at the index, when that neighbor is
	 * a statement the kinds speak of, not one `isForeign()` leaves to the decisions of the header and the declarations.
	 * @param array<string, int|array{int, ?int}|null> $counts
	 */
	private function claimByKind(Node|Token $stmt, int $neighbor, array $counts, string $key): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof StatementNode || !$list instanceof PlainNodeList || self::isForeign($stmt)) {
			return null;
		}

		$other = $list->getItems()[$neighbor] ?? null;
		$count = $other instanceof StatementNode && !self::isForeign($other) ? $counts[self::classifyStatement($stmt)] ?? null : null;
		return $this->claims->claim($count, $key);
	}


	/** The statement after the opening tag: the first one, or the one after the hashbang or the BOM. */
	private static function findFirstCode(FileNode $file): ?StatementNode
	{
		$stmts = $file->statements->getItems();
		$first = $stmts[0] ?? null;
		$stmt = $first instanceof Statement\InlineHtmlNode ? $stmts[1] ?? null : $first;
		return ($stmt?->getFirstToken()?->leadingTrivia[0] ?? null)?->is(Trivia::OpenTag) ? $stmt : null;
	}


	/** PHP only: no markup but a hashbang or BOM at the start, and no closing tag. */
	private static function isMonolithic(FileNode $file): bool
	{
		foreach ($file->statements->getItems() as $i => $stmt) {
			if ($stmt instanceof Statement\InlineHtmlNode && ($i > 0 || !$stmt->isPreamble())) {
				return false;
			}
		}

		for ($token = $file->getFirstToken(); $token !== null; $token = $token->getNext()) {
			if ($token->is(Token::CloseTag)) {
				return false;
			}
		}

		return true;
	}


	private static function classifyStatement(StatementNode $stmt): string
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


	/** Statements whose blank lines the decisions about the header and the declarations own. */
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
