<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Rules\BlankLines;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\{AnonymousClassNode, CaseNode, CatchNode, ClassLikeNode, ElseIfNode, ElseNode, Expression, FileNode, FinallyNode, Member, MemberNode, NodeList, Statement, StatementNode};
use PhpSyntax\Nodes\Expression\MatchNode;
use function array_slice, count;


/**
 * How many blank lines stand where: in the header of a file, around declarations, around the braces and members
 * of a class, inside the braces of a block, and between statements of a kind. Comments stay with the code below
 * the blank lines, and a doc comment counts as part of what it documents.
 */
#[RuleInfo(
	'dresscode/blank-lines',
	Stage::Formatting,
	description: 'Puts blank lines where the standard asks for them, in the header, the class, the block and between statements',
)]
final class BlankLinesRule extends GapRule implements ConfigurableRule
{
	private const ClassLikes = [
		Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
		AnonymousClassNode::class,
	];
	private const Blocks = [Statement\BlockNode::class, Statement\SwitchNode::class, MatchNode::class];
	private const Kinds = ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'switch', 'throw', 'try', 'while', 'yield'];
	private const Paragraphed = 'paragraphed';

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeNamespace = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterOpeningTag = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterNamespace = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterImports = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenImportGroups = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeDeclaration = null;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenDeclarations = 2;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenMethods = 2;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenMethodsInInterface = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeFirstMethod = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterLastMethod = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterClassBrace = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeClassBrace = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenTraitUses = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterTraitUses = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenMembers = [0, 1];

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeDocumentedMember = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterPhpDoc = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterBlockBrace = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeBlockBrace = null;

	/** @var int|array{int, ?int}|self::Paragraphed|null */
	private int|array|string|null $betweenBranches = null;

	/** @var int|array{int, ?int}|self::Paragraphed|null */
	private int|array|string|null $betweenCases = null;

	/** @var array<string, int|array{int, ?int}|null> */
	private array $before = ['return' => [1, null]];

	/** @var array<string, int|array{int, ?int}|null> */
	private array $after = [];


	public static function getOptionsSchema(): Schema
	{
		$counts = fn() => Expect::arrayOf(BlankLines::schema(BlankLines::Keep), Expect::anyOf(...self::Kinds));
		return Expect::structure([
			// the header of the file
			'afterOpeningTag' => BlankLines::schema(1)->description('After the line of <?php, which then carries no code; a file with markup outside PHP keeps its tag'),
			'beforeNamespace' => BlankLines::schema(1)->description('Before the namespace declaration'),
			'afterNamespace' => BlankLines::schema(1)->description('After an unbraced namespace declaration'),
			'afterImports' => BlankLines::schema(1)->description('After the last import, before the rest of the code'),
			'betweenImportGroups' => BlankLines::schema(1)->description('Between imports of classes, functions and constants; imports of one group never have a blank line between them'),
			'beforeDeclaration' => BlankLines::schema(BlankLines::Keep)->description('Before a class, interface, trait, enum or function that follows the namespace or the imports, in place of afterNamespace and afterImports'),

			// the declarations among statements
			'betweenDeclarations' => BlankLines::schema(2)
				->description('Before and after a class, interface, trait, enum or function declared among statements, where the header above it does not decide'),

			// the class and its members
			'betweenMethods' => BlankLines::schema(2)
				->description('Before and after a method; the first and last method of a class use beforeFirstMethod and afterLastMethod'),
			'betweenMethodsInInterface' => BlankLines::schema(1),
			'beforeFirstMethod' => BlankLines::schema(0)->description('Before a method that is the first member of its class'),
			'afterLastMethod' => BlankLines::schema(0)->description('After a method that is the last member of its class'),
			'afterClassBrace' => BlankLines::schema(0)
				->description('Before the first member, unless it is a method: then beforeFirstMethod applies'),
			'beforeClassBrace' => BlankLines::schema(0)
				->description('After the last member, unless it is a method: then afterLastMethod applies'),
			'betweenTraitUses' => BlankLines::schema(0),
			'afterTraitUses' => BlankLines::schema(1)
				->description('Before the member following the trait uses, a method among them'),
			'betweenMembers' => BlankLines::schema([0, 1])
				->description('Between properties, constants and enum cases without a doc comment or attribute'),
			'beforeDocumentedMember' => BlankLines::schema(1)
				->description('Before a property, constant or enum case with a doc comment or an attribute'),
			'afterPhpDoc' => BlankLines::schema(0)
				->description('Between a doc comment or an attribute and the declaration it belongs to'),

			// the braces of a block: a function body, a control structure, a switch or a match
			'afterBlockBrace' => BlankLines::schema(0)->description('After the opening brace of a block'),
			'beforeBlockBrace' => BlankLines::schema(BlankLines::Keep)
				->description('Before the closing brace of a block; kept by default, because such a line separates the branches of a } catch or } else chain'),
			'betweenBranches' => Expect::anyOf(BlankLines::schema(BlankLines::Keep), self::Paragraphed)->default(BlankLines::Keep)
				->description('Before the closing brace of a branch of if or try that else, elseif, catch or finally follows, in place of beforeBlockBrace; paragraphed asks for one in every branch of a chain where a branch is split into paragraphs by a blank line, and keeps the others'),
			'betweenCases' => Expect::anyOf(BlankLines::schema(BlankLines::Keep), self::Paragraphed)->default(BlankLines::Keep)
				->description('Before a case or default of a switch that follows a case with statements, below a comment right under those statements; paragraphed asks for one before every such case of a switch where a case is split into paragraphs, and keeps the others'),

			// the statements of a block, by their kind
			'before' => $counts()->default(['return' => [1, null]])
				->description('Blank lines before a statement of the kind, as a count or a range [min, max] with null for no bound; yield means a statement made of a yield expression'),
			'after' => $counts()->description('Blank lines after a statement of the kind, before the next statement'),
		]);
	}


	public function configure(array $options): void
	{
		foreach ($options as $name => $value) {
			$this->$name = match (true) {
				$name === 'before' || $name === 'after' => array_map(BlankLines::count(...), $value),
				$value === self::Paragraphed => $value,
				default => BlankLines::count($value),
			};
		}
	}


	public function getClaims(): array
	{
		$header = ['statements:item' => [fn(Gap $gap) => $this->inHeader($gap->value, $gap->index ?? 0), null]];
		$claims = [
			FileNode::class => $header,
			Statement\NamespaceNode::class => $header,
			'*' => [
				'statements:item' => [
					fn(Gap $gap) => $this->beforeStatement($gap->value, $gap->index ?? 0),
					fn(Gap $gap) => $this->afterStatement($gap->value, $gap->index ?? 0),
				],
				'attributes' => [null, $this->afterPhpDoc === null ? null : Claim::blank($this->afterPhpDoc)],
			],
		];
		foreach (self::ClassLikes as $class) {
			$claims[$class] = [
				'members:item' => [
					fn(Gap $gap) => $this->beforeMember($gap->value, $gap->index ?? 0),
					fn(Gap $gap) => $this->afterMethod($gap->value, $gap->index ?? 0),
				],
				'closeBrace' => [fn(Gap $gap) => $this->beforeClassBrace($gap->token), null],
			];
		}

		// an empty block has one gap, not two, and it belongs to the brace that closes it
		$after = $this->afterBlockBrace === null ? null : Claim::blank($this->afterBlockBrace);
		$before = $this->beforeBlockBrace === null ? null : Claim::blank($this->beforeBlockBrace);
		$braces = [
			'openBrace' => [null, fn(Gap $gap) => ($gap->token->getNext()?->is('}') ?? false) ? null : $after],
			'closeBrace' => [$before, null],
		];
		foreach (self::Blocks as $class) {
			$claims[$class] = $braces;
		}

		if ($this->betweenBranches !== null) {
			$between = Claim::blank($this->betweenBranches === self::Paragraphed ? 1 : $this->betweenBranches);
			$claims[Statement\BlockNode::class]['closeBrace'][0] = fn(Gap $gap) => $this->isBetweenBranches($gap) ? $between : $before;
		}

		if ($this->betweenCases !== null) {
			$count = $this->betweenCases === self::Paragraphed ? 1 : $this->betweenCases;
			$above = Claim::blank($count);
			$below = new Claim(blankBelowComment: $count);
			$claims[Statement\SwitchNode::class]['cases:item'] = [fn(Gap $gap) => $this->beforeCase($gap, $above, $below), null];
		}

		return $claims;
	}


	/**
	 * What the header asks for above the statement: the line of the opening tag above the first, the namespace
	 * above its statements, the imports above what follows them, all of it where the statement follows
	 * several of these.
	 */
	private function inHeader(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof StatementNode || !$list instanceof NodeList) {
			return null;
		}

		$owner = $list->parent;
		$previous = $list->getItems()[$index - 1] ?? null;
		$counts = [];
		$line = null;
		if (
			$owner instanceof FileNode
			&& $stmt === self::findFirstCode($owner)
			&& $this->afterOpeningTag !== null
			&& self::isMonolithic($owner)
		) {
			$counts[] = $this->afterOpeningTag;
			$line = Line::Next; // the tag ends its line before the blank lines after it can be counted
		}

		if ($stmt instanceof Statement\NamespaceNode && $this->beforeNamespace !== null) {
			$counts[] = $this->beforeNamespace;
		}

		$declared = $stmt instanceof ClassLikeNode || $stmt instanceof Statement\FunctionNode ? $this->beforeDeclaration : null;
		if ($owner instanceof Statement\NamespaceNode && $owner->semicolon !== null && $index === 0) {
			$counts[] = $declared ?? $this->afterNamespace;
		}

		if ($previous instanceof Statement\UseNode) {
			$counts[] = match (true) {
				!$stmt instanceof Statement\UseNode => $declared ?? $this->afterImports,
				self::getImportGroup($previous) === self::getImportGroup($stmt) => 0,
				default => $this->betweenImportGroups,
			};
		}

		$count = BlankLines::intersect(array_values(array_filter($counts, fn($count) => $count !== null)));
		return $count === null && $line === null ? null : new Claim(line: $line, blank: $count);
	}


	/**
	 * Before a declaration among statements and below its doc comment, else what the kind of the statement
	 * asks for; the two never answer for the same statement.
	 */
	private function beforeStatement(Node|Token $stmt, int $index): ?Claim
	{
		$declaration = $stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode ? $stmt : null;
		$blank = $declaration !== null && $index > 0 ? $this->betweenDeclarations : null;
		$below = $declaration === null ? null : $this->belowDocComment($declaration);
		return $blank === null && $below === null
			? $this->byKind($stmt, $index - 1, $this->before)
			: new Claim(blank: $blank, blankBelowComment: $below);
	}


	/** After a declaration among statements, before what is not one, else what the kind of the statement asks for. */
	private function afterStatement(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if (($stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode) && $list instanceof NodeList) {
			$next = $list->getItems()[$index + 1] ?? null;
			return $next !== null
				&& !$next instanceof Statement\FunctionNode
				&& !$next instanceof ClassLikeNode
				&& $this->betweenDeclarations !== null
				? Claim::blank($this->betweenDeclarations)
				: null;
		}

		return $this->byKind($stmt, $index + 1, $this->after);
	}


	/**
	 * The count the statement's kind asks for on the side of its neighbor at the index, when that neighbor is
	 * a statement the kinds speak of, not one `isForeign()` leaves to the options of the header and the declarations.
	 * @param array<string, int|array{int, ?int}|null> $counts
	 */
	private function byKind(Node|Token $stmt, int $neighbor, array $counts): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof StatementNode || !$list instanceof NodeList || self::isForeign($stmt)) {
			return null;
		}

		$other = $list->getItems()[$neighbor] ?? null;
		$count = $other instanceof StatementNode && !self::isForeign($other) ? $counts[self::kindOf($stmt)] ?? null : null;
		return $count === null ? null : Claim::blank($count);
	}


	/** Before a member of a class, by what it is and what stands above it. */
	private function beforeMember(Node|Token $member, int $index): ?Claim
	{
		$list = $member->parent;
		if (!$member instanceof MemberNode || !$list instanceof NodeList) {
			return null;
		}

		$previous = $list->getItems()[$index - 1] ?? null;
		$documented = $member instanceof Member\PropertyNode || $member instanceof Member\ClassConstNode || $member instanceof Member\EnumCaseNode
			? !$member->attributes->isEmpty() || $member->getDocComment() !== null
			: false;
		$count = match (true) {
			$member instanceof Member\MethodNode => match (true) {
				$index === 0 => $this->beforeFirstMethod,
				$previous instanceof Member\TraitUseNode => $this->afterTraitUses,
				default => $this->betweenMethodsOf($list),
			},
			$previous === null => $this->afterClassBrace,
			$member instanceof Member\TraitUseNode => $previous instanceof Member\TraitUseNode ? $this->betweenTraitUses : null,
			$previous instanceof Member\TraitUseNode => $this->afterTraitUses,
			$previous instanceof Member\MethodNode => null, // the method claims the gap after itself
			$documented => $this->beforeDocumentedMember,
			default => $this->betweenMembers,
		};
		$below = $this->belowDocComment($member);
		return $count === null && $below === null ? null : new Claim(blank: $count, blankBelowComment: $below);
	}


	/**
	 * What the option asks for below the doc comment of a declaration: the last comment above it has to be one,
	 * and not the file's header; the engine counts below the last comment.
	 * @return int|array{int, ?int}|null
	 */
	private function belowDocComment(Node $node): int|array|null
	{
		if ($this->afterPhpDoc === null) {
			return null;
		}

		$leading = $node->getFirstToken()->leadingTrivia ?? [];
		$at = null;
		foreach ($leading as $i => $trivia) {
			if ($trivia->isComment()) {
				$at = $i;
			}
		}

		return $at !== null && $leading[$at]->kind === TriviaKind::DocComment && !self::isFileHeader($leading, $at)
			? $this->afterPhpDoc
			: null;
	}


	/** After a method, before a member that is not a method or before the closing brace of the class. */
	private function afterMethod(Node|Token $member, int $index): ?Claim
	{
		$list = $member->parent;
		if (!$member instanceof Member\MethodNode || !$list instanceof NodeList) {
			return null;
		}

		$next = $list->getItems()[$index + 1] ?? null;
		$count = match (true) {
			$next === null => $this->afterLastMethod,
			$next instanceof Member\MethodNode => null,
			default => $this->betweenMethodsOf($list),
		};
		return $count === null ? null : Claim::blank($count);
	}


	/** Before the closing brace of a class, unless a method stands last and claims the gap after itself. */
	private function beforeClassBrace(Token $brace): ?Claim
	{
		$class = $brace->parent;
		$members = $class instanceof ClassLikeNode ? $class->members->getItems() : [];
		$last = $members[count($members) - 1] ?? null;
		return $last instanceof Member\MethodNode || $this->beforeClassBrace === null
			? null
			: Claim::blank($this->beforeClassBrace);
	}


	/**
	 * @param NodeList<MemberNode> $members
	 * @return int|array{int, ?int}|null
	 */
	private function betweenMethodsOf(NodeList $members): int|array|null
	{
		return $members->parent instanceof Statement\InterfaceNode ? $this->betweenMethodsInInterface : $this->betweenMethods;
	}


	/**
	 * Whether the closing brace ends a branch another branch of its if or try follows, which betweenBranches
	 * then decides, an empty one left to beforeBlockBrace; paragraphed decides once for the whole chain, so that
	 * its branches are all set apart or all left to beforeBlockBrace.
	 */
	private function isBetweenBranches(Gap $gap): bool
	{
		$block = $gap->token->parent;
		if (!$block instanceof Statement\BlockNode || $block->statements->getItems() === []) {
			return false;
		}

		$branch = $block->parent;
		$chain = match (true) {
			$branch instanceof ElseIfNode, $branch instanceof CatchNode => $branch->parent?->parent,
			$branch instanceof ElseNode, $branch instanceof FinallyNode => $branch->parent,
			default => $branch,
		};
		$branches = $chain === null ? [] : self::getBranches($chain);
		if ($chain === null || !in_array($block, array_slice($branches, 0, -1), true)) {
			return false;
		}

		return $this->betweenBranches !== self::Paragraphed || $gap->once($chain, fn() => array_any(
			$branches,
			fn($body) => $body instanceof Statement\BlockNode && self::isParagraphed($body),
		));
	}


	/**
	 * Before a case that follows a case with statements; paragraphed decides once for the whole switch. A comment
	 * right under those statements (a fall-through) belongs to them, so the blank lines go below it; one set apart
	 * by a blank line goes with the case.
	 */
	private function beforeCase(Gap $gap, Claim $above, Claim $below): ?Claim
	{
		$case = $gap->value;
		$list = $case->parent;
		$switch = $list?->parent;
		$previous = $list instanceof NodeList ? $list->getItems()[($gap->index ?? 0) - 1] ?? null : null;
		if (
			!$case instanceof CaseNode
			|| !$previous instanceof CaseNode
			|| $previous->statements->isEmpty()
			|| !$switch instanceof Statement\SwitchNode
			|| ($this->betweenCases === self::Paragraphed && !$gap->once($switch, fn() => array_any(
				$switch->cases->getItems(),
				fn(CaseNode $other) => self::hasParagraphs($other->statements),
			)))
		) {
			return null;
		}

		$keyword = $case->caseKeyword;
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
		return ($stmt?->getFirstToken()?->leadingTrivia[0] ?? null)?->kind === TriviaKind::OpenTag ? $stmt : null;
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
			if ($token->is(TokenKind::CloseTag)) {
				return false;
			}
		}

		return true;
	}


	private static function getImportGroup(Statement\UseNode $import): string
	{
		return strtolower($import->type->text ?? '');
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


	/** Statements whose blank lines the options about the header and the declarations own. */
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
		if (($leading[0] ?? null)?->kind !== TriviaKind::OpenTag) {
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
				...array_map(fn(ElseIfNode $branch) => $branch->body, $chain->elseifs->getItems()),
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
	 * Whether a blank line splits the statements of the block, or sets apart a comment that ends it, which
	 * belongs to the statements above it.
	 */
	private static function isParagraphed(Statement\BlockNode $block): bool
	{
		if (self::hasParagraphs($block->statements)) {
			return true;
		}

		$comment = null;
		foreach ($block->closeBrace->leadingTrivia as $i => $trivia) {
			if ($trivia->isComment()) {
				$comment = $i;
			}
		}

		return !$block->statements->isEmpty() && $comment !== null && self::hasBlankLine($block->closeBrace, $comment);
	}


	/**
	 * Whether a blank line stands between two of the statements.
	 * @param NodeList<StatementNode> $stmts
	 */
	private static function hasParagraphs(NodeList $stmts): bool
	{
		foreach (array_slice($stmts->getItems(), 1) as $stmt) {
			$first = $stmt->getFirstToken();
			if ($first !== null && self::hasBlankLine($first)) {
				return true;
			}
		}

		return false;
	}


	/** Whether a blank line stands in the leading trivia of the token, among the first ones up to the index. */
	private static function hasBlankLine(Token $token, ?int $end = null): bool
	{
		$trailing = $token->getPrevious()->trailingTrivia ?? [];
		$lineStart = ($trailing[count($trailing) - 1] ?? null)?->isEndOfLine() ?? false;
		foreach (array_slice($token->leadingTrivia, 0, $end) as $trivia) {
			if ($trivia->isEndOfLine()) {
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
