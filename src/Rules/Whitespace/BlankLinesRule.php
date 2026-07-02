<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Value, Values};
use DressCode\Domains\{Count, Map};
use DressCode\Engine\Helpers;
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousClassNode, CaseNode, CatchNode, ClassLikeNode, ElseifNode, ElseNode, Expression, FileNode, FinallyNode, Member, MemberNode, PlainNodeList, Statement, StatementNode};
use PhpSyntax\Nodes\Expression\MatchNode;
use function count, is_int, is_string;


/**
 * How many blank lines stand where: in the header of a file, around declarations, around the braces and members
 * of a class, inside the braces of a block, and between statements of a kind. Comments stay with the code below
 * the blank lines, and a doc comment counts as part of what it documents.
 */
#[RuleInfo(Stage::Formatting)]
final class BlankLinesRule extends GapRule
{
	private const ClassLikes = [
		Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
		AnonymousClassNode::class,
	];
	private const Blocks = [Statement\BlockNode::class, Statement\SwitchNode::class, MatchNode::class];
	private const Kinds = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];
	private const LastSetApart = 'lastSetApart';
	private const Counts = [
		'afterOpeningTag' => 'After the line of `<?php`, which then carries no code; a file with markup outside PHP keeps its tag',
		'beforeNamespace' => 'Before the namespace declaration and, where a doc comment of the file stands above it, also between the two',
		'afterNamespace' => 'After an unbraced namespace declaration',
		'afterImports' => 'After the last import, before the rest of the code',
		'betweenImportKinds' => 'Between the imports of classes, functions and constants; imports of one kind never have a blank line between them',
		'beforeDeclaration' => 'Before a class, interface, trait, enum or function that follows the namespace or the imports, in place of `afterNamespace` and `afterImports`',
		'betweenDeclarations' => 'Before and after a class, interface, trait, enum or function declared among statements, where the header above it does not decide',
		'betweenMethods' => 'Before and after a method; the first and the last method of a class take `beforeFirstMethod` and `afterLastMethod`',
		'betweenInterfaceMethods' => 'Before and after a method of an interface, in place of `betweenMethods`',
		'beforeFirstMethod' => 'Before a method that is the first member of its class',
		'afterLastMethod' => 'After a method that is the last member of its class',
		'beforeFirstMember' => 'Before the first member, unless it is a method, which takes `beforeFirstMethod`',
		'afterLastMember' => 'After the last member, unless it is a method, which takes `afterLastMethod`',
		'betweenTraitUses' => 'Between the trait uses of a class',
		'afterTraitUses' => 'Before the first member after the trait uses, a method included',
		'betweenMembers' => 'Between properties, constants and enum cases without a doc comment or an attribute',
		'beforeDocumentedMember' => 'Before a property, constant or enum case with a doc comment or an attribute',
		'afterPhpdoc' => 'Between a doc comment or an attribute and the declaration it belongs to',
		'afterBlockOpeningBrace' => 'After the opening brace of a block',
		'beforeBlockClosingBrace' => 'Before the closing brace of a block',
	];

	/** @var array<string, int|array{int, ?int}|null>  the decision of a count => its count, null where it is kept */
	private array $counts = [];

	/** @var int|array{int, ?int}|self::LastSetApart|null */
	private int|array|string|null $betweenBranches = null;

	/** @var int|array{int, ?int}|self::LastSetApart|null */
	private int|array|string|null $betweenCases = null;

	/** @var array<string, int|array{int, ?int}>  kind => count, a kind left as it is not being there */
	private array $beforeStatement = ['return' => [1, null]];

	/** @var array<string, int|array{int, ?int}> */
	private array $afterStatement = [];

	/** @var array<string, Claim>  by the decision and the count */
	private array $claims = [];


	public static function getDecisions(): array
	{
		$count = Domain::blankLines();
		$lastSetApart = new Count(words: ['lastStatementSetApart' => 'one where the last statement of the branch stands apart, which would otherwise read as belonging to the next one, the others kept']);
		$kinds = new Map($count, wildcards: false, words: array_fill_keys(self::Kinds, ''));
		$decisions = [];
		foreach (self::Counts as $key => $description) {
			$decisions[] = new Decision("blankLines.$key", $count, $description);
		}

		foreach ([
			'betweenBranches' => [
				$lastSetApart,
				'Before the closing brace of a branch of `if` or `try` that `else`, `elseif`, `catch` or `finally` follows, in place of `beforeBlockClosingBrace`',
			],
			'betweenCases' => [
				$lastSetApart,
				'Before a `case` or `default` of a `switch` that follows a case with statements, below a comment right under those statements',
			],
			'beforeStatement' => [$kinds, 'Before a statement of the kind, `yield` meaning a statement made of a `yield` expression'],
			'afterStatement' => [$kinds, 'After a statement of the kind, before the next statement'],
		] as $key => [$domain, $description]) {
			$decisions[] = new Decision("blankLines.$key", $domain, $description);
		}

		return $decisions;
	}


	public function configure(Values $values): void
	{
		$read = fn(string $key) => self::readCount($values->get("blankLines.$key"));
		foreach (array_keys(self::Counts) as $key) {
			$this->counts[$key] = $read($key);
		}

		$readSetApart = fn(string $key) => !$values->isKept("blankLines.$key") && is_string($values->get("blankLines.$key")->content)
			? self::LastSetApart
			: $read($key);
		$this->betweenBranches = $readSetApart('betweenBranches');
		$this->betweenCases = $readSetApart('betweenCases');

		$readKinds = fn(string $key) => $values->isKept("blankLines.$key")
			? []
			: array_filter(array_map(self::readCount(...), $values->get("blankLines.$key")->getEntries()), fn($count) => $count !== null);
		$this->beforeStatement = $readKinds('beforeStatement');
		$this->afterStatement = $readKinds('afterStatement');
	}


	/**
	 * The count a value decides, null where the place is left alone.
	 * @return int|array{int, ?int}|null
	 */
	private static function readCount(Value $value): int|array|null
	{
		return $value->isKept() ? null : self::compact($value->getCount());
	}


	/**
	 * @param  array{int, ?int}  $range
	 * @return int|array{int, ?int}
	 */
	private static function compact(array $range): int|array
	{
		return $range[0] === $range[1] ? $range[0] : $range;
	}


	/**
	 * The claim of the count made for the decision of the key, and below a comment for another one.
	 * @param  int|array{int, ?int}|null  $count
	 * @param  int|array{int, ?int}|null  $below
	 */
	private function claim(
		int|array|null $count,
		string $key,
		int|array|null $below = null,
		?string $belowKey = null,
		?Line $line = null,
	): ?Claim
	{
		if ($count === null && $below === null && $line === null) {
			return null;
		} elseif ($below === null && $line === null) {
			return $this->claims[$key . ' ' . (is_int($count) ? $count : implode('-', $count ?? []))]
				??= new Claim(blankLines: $count, decision: "blankLines.$key");
		}

		return new Claim(
			line: $line,
			blankLines: $count,
			blankLinesBelowComment: $below,
			decision: "blankLines.$key",
			decisionBelowComment: $belowKey === null ? null : "blankLines.$belowKey",
		);
	}


	/** The claim of the count the decision of the key gives. */
	private function claimCount(string $key): ?Claim
	{
		return $this->claim($this->counts[$key], $key);
	}


	public function getClaims(): array
	{
		$header = ['statements:item' => [fn(Gap $gap) => $this->claimInHeader($gap->value, $gap->index ?? 0), null]];
		$claims = [
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
				'attributes' => [null, $this->claimCount('afterPhpdoc')],
			],
		];
		foreach (self::ClassLikes as $class) {
			$claims[$class] = [
				'members:item' => [
					fn(Gap $gap) => $this->claimBeforeMember($gap->value, $gap->index ?? 0),
					fn(Gap $gap) => $this->claimAfterMethod($gap->value, $gap->index ?? 0),
				],
				'closeBrace' => [fn(Gap $gap) => $this->claimAfterLastMember($gap->token), null],
			];
		}

		// an empty block has one gap, not two, and it belongs to the brace that closes it
		$after = $this->claimCount('afterBlockOpeningBrace');
		$before = $this->claimCount('beforeBlockClosingBrace');
		$braces = [
			'openBrace' => [null, $after === null ? null : fn(Gap $gap) => ($gap->token->getNext()?->is('}') ?? false) ? null : $after],
			'closeBrace' => [$before, null],
		];
		foreach (self::Blocks as $class) {
			$claims[$class] = $braces;
		}

		if ($this->betweenBranches !== null) {
			$between = $this->claim($this->betweenBranches === self::LastSetApart ? 1 : $this->betweenBranches, 'betweenBranches');
			$claims[Statement\BlockNode::class]['closeBrace'][0] = fn(Gap $gap) => $this->isBetweenBranches($gap) ? $between : $before;
		}

		if ($this->betweenCases !== null) {
			$count = $this->betweenCases === self::LastSetApart ? 1 : $this->betweenCases;
			$above = new Claim(blankLines: $count, decision: 'blankLines.betweenCases');
			$below = new Claim(blankLinesBelowComment: $count, decision: 'blankLines.betweenCases');
			$claims[Statement\SwitchNode::class]['cases:item'] = [fn(Gap $gap) => $this->claimBeforeCase($gap, $above, $below), null];
		}

		return $claims;
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
				$previous->symbolKind === $stmt->symbolKind => [0, 'betweenImportKinds'],
				default => [$this->counts['betweenImportKinds'], 'betweenImportKinds'],
			};
		}

		$count = self::intersect(array_values(array_filter($counts, fn($count) => $count !== null)));
		return $this->claim($count, $key, $below, 'beforeNamespace', $line);
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
		$below = $declaration === null ? null : $this->belowDocComment($declaration);
		return $blank === null && $below === null
			? $this->claimByKind($stmt, $index - 1, $this->beforeStatement, 'beforeStatement')
			: $this->claim($blank, 'betweenDeclarations', $below, 'afterPhpdoc');
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
				&& $this->counts['betweenDeclarations'] !== null
					? $this->claimCount('betweenDeclarations')
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
		return $this->claim($count, $key);
	}


	/** Before a member of a class, by what it is and what stands above it. */
	private function claimBeforeMember(Node|Token $member, int $index): ?Claim
	{
		$list = $member->parent;
		if (!$member instanceof MemberNode || !$list instanceof PlainNodeList) {
			return null;
		}

		$previous = $list->getItems()[$index - 1] ?? null;
		$documented = $member instanceof Member\PropertyNode || $member instanceof Member\ClassConstNode || $member instanceof Member\EnumCaseNode
			? !$member->attributes->isEmpty() || $member->getDocComment() !== null
			: false;
		$key = match (true) {
			$member instanceof Member\MethodNode => match (true) {
				$index === 0 => 'beforeFirstMethod',
				$previous instanceof Member\TraitUseNode => 'afterTraitUses',
				default => self::chooseBetweenMethods($list),
			},
			$previous === null => 'beforeFirstMember',
			$member instanceof Member\TraitUseNode => $previous instanceof Member\TraitUseNode ? 'betweenTraitUses' : null,
			$previous instanceof Member\TraitUseNode => 'afterTraitUses',
			$previous instanceof Member\MethodNode => null, // the method claims the gap after itself
			$documented => 'beforeDocumentedMember',
			default => 'betweenMembers',
		};
		return $this->claim($key === null ? null : $this->counts[$key], $key ?? 'afterPhpdoc', $this->belowDocComment($member), 'afterPhpdoc');
	}


	/**
	 * What the decision asks for below the doc comment of a declaration: the last comment above it has to be one,
	 * and not the file's header; the engine counts below the last comment.
	 * @return int|array{int, ?int}|null
	 */
	private function belowDocComment(Node $node): int|array|null
	{
		if ($this->counts['afterPhpdoc'] === null) {
			return null;
		}

		$leading = $node->getFirstToken()->leadingTrivia ?? [];
		$at = Helpers::findLastCommentIndex($leading);

		return $at !== null && $leading[$at]->is(Trivia::DocComment) && !self::isFileHeader($leading, $at)
			? $this->counts['afterPhpdoc']
			: null;
	}


	/** After a method, before a member that is not a method or before the closing brace of the class. */
	private function claimAfterMethod(Node|Token $member, int $index): ?Claim
	{
		$list = $member->parent;
		if (!$member instanceof Member\MethodNode || !$list instanceof PlainNodeList) {
			return null;
		}

		$next = $list->getItems()[$index + 1] ?? null;
		if ($next instanceof Member\MethodNode) {
			return null; // the method below claims the gap before itself
		}

		return $this->claimCount($next === null ? 'afterLastMethod' : self::chooseBetweenMethods($list));
	}


	/** Before the closing brace of a class, unless a method stands last and claims the gap after itself. */
	private function claimAfterLastMember(Token $brace): ?Claim
	{
		$class = $brace->parent;
		$members = $class instanceof ClassLikeNode ? $class->members->getItems() : [];
		$last = $members[count($members) - 1] ?? null;
		return $last instanceof Member\MethodNode ? null : $this->claimCount('afterLastMember');
	}


	/**
	 * The decision of the count between the methods of the class.
	 * @param  PlainNodeList<MemberNode>  $members
	 */
	private static function chooseBetweenMethods(PlainNodeList $members): string
	{
		return $members->parent instanceof Statement\InterfaceNode ? 'betweenInterfaceMethods' : 'betweenMethods';
	}


	/**
	 * Whether the closing brace ends a branch another branch of its if or try follows, which betweenBranches
	 * then decides, an empty one left to beforeBlockClosingBrace; lastSetApart takes only a branch whose last statement
	 * stands apart from the ones above it, which would otherwise read as the start of the next branch.
	 */
	private function isBetweenBranches(Gap $gap): bool
	{
		$block = $gap->token->parent;
		if (!$block instanceof Statement\BlockNode || $block->statements->getItems() === []) {
			return false;
		}

		$branch = $block->parent;
		$chain = match (true) {
			$branch instanceof ElseifNode, $branch instanceof CatchNode => $branch->parent?->parent,
			$branch instanceof ElseNode, $branch instanceof FinallyNode => $branch->parent,
			default => $branch,
		};
		$branches = $chain === null ? [] : self::getBranches($chain);
		if ($chain === null || !in_array($block, array_slice($branches, 0, -1), true)) {
			return false;
		}

		return $this->betweenBranches !== self::LastSetApart || self::isEndSetApart($block);
	}


	/**
	 * Before a case that follows a case with statements; lastSetApart takes only the one after a case whose last
	 * statement stands apart. A comment right under those statements (a fall-through) belongs to them, so the
	 * blank lines go below it; one set apart by a blank line goes with the case.
	 */
	private function claimBeforeCase(Gap $gap, Claim $above, Claim $below): ?Claim
	{
		$case = $gap->value;
		$list = $case->parent;
		$switch = $list?->parent;
		$previous = $list instanceof PlainNodeList ? $list->getItems()[($gap->index ?? 0) - 1] ?? null : null;
		if (
			!$case instanceof CaseNode
			|| !$previous instanceof CaseNode
			|| $previous->statements->isEmpty()
			|| !$switch instanceof Statement\SwitchNode
			|| ($this->betweenCases === self::LastSetApart && !self::isLastSetApart($previous->statements))
		) {
			return null;
		}

		$keyword = $case->keyword;
		foreach ($keyword->leadingTrivia as $i => $trivia) {
			if ($trivia->isComment()) {
				return self::hasBlankLine($keyword, $i) ? $above : $below;
			}
		}

		return $above;
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


	/**
	 * A doc comment that is the first thing after the opening tag of the file belongs to the file, not to
	 * the declaration below it.
	 * @param list<Trivia> $leading
	 */
	private static function isFileHeader(array $leading, int $at): bool
	{
		if (!($leading[0] ?? null)?->is(Trivia::OpenTag)) {
			return false;
		}

		return array_all(array_slice($leading, 1, $at - 1), fn(Trivia $trivia) => $trivia->isWhitespace());
	}


	/**
	 * The bodies of the branches of an if or a try in their order, a missing else or finally left out.
	 * @return list<StatementNode>
	 */
	private static function getBranches(Node $chain): array
	{
		$bodies = match (true) {
			$chain instanceof Statement\IfNode => [
				$chain->body,
				...array_map(fn(ElseifNode $branch) => $branch->body, $chain->elseifs->getItems()),
				$chain->else?->body,
			],
			$chain instanceof Statement\TryNode => [
				$chain->body,
				...array_map(fn(CatchNode $branch) => $branch->body, $chain->catches->getItems()),
				$chain->finally?->body,
			],
			default => [],
		};
		return array_values(array_filter($bodies, fn($body) => $body !== null));
	}


	/**
	 * Whether the block ends with a statement set apart from the ones above it by a blank line, or with a comment
	 * set apart so, which belongs to the statements above it.
	 */
	private static function isEndSetApart(Statement\BlockNode $block): bool
	{
		if (self::isLastSetApart($block->statements)) {
			return true;
		}

		$comment = Helpers::findLastCommentIndex($block->closeBrace->leadingTrivia);
		return !$block->statements->isEmpty() && $comment !== null && self::hasBlankLine($block->closeBrace, $comment);
	}


	/**
	 * Whether a blank line stands above the last of the statements, and some statement above it. A statement that
	 * ends with a brace of its own closes itself, so it does not read as the start of what follows.
	 * @param PlainNodeList<StatementNode> $stmts
	 */
	private static function isLastSetApart(PlainNodeList $stmts): bool
	{
		$items = $stmts->getItems();
		$last = count($items) > 1 ? $items[count($items) - 1] : null;
		$first = $last?->getFirstToken();
		return $first !== null && $last->getLastToken()->text !== '}' && self::hasBlankLine($first);
	}


	/** Whether a blank line stands in the leading trivia of the token, among the first ones up to the index. */
	private static function hasBlankLine(Token $token, ?int $end = null): bool
	{
		$trailing = $token->getPrevious()->trailingTrivia ?? [];
		$lineStart = ($trailing[count($trailing) - 1] ?? null)?->isLineEnding() ?? false;
		foreach (array_slice($token->leadingTrivia, 0, $end) as $trivia) {
			if ($trivia->isLineEnding()) {
				if ($lineStart) {
					return true;
				}

				$lineStart = true;

			} elseif (!$trivia->isWhitespace()) {
				$lineStart = false;
			}
		}

		return false;
	}
}
