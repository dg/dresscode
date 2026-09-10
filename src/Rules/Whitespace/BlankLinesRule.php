<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Rules\BlankLines;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Member;
use PhpSyntax\Nodes\MemberNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function array_slice, count;


/**
 * How many blank lines stand where: in the header of a file, around the braces and members of a class,
 * inside the braces of a block, and between statements of a kind. Every count is a number, a range
 * `[min, max]` with an open end as null, or `keep` for a place the rule leaves alone. Comments stay with
 * the code below the blank lines, and a doc comment counts as part of what it documents.
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
	private int|array|null $betweenFunctions = 2;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenFunctionsInInterface = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeFirstFunction = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterLastFunction = 0;

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

			// the class and its members
			'betweenFunctions' => BlankLines::schema(2)
				->description('Before and after a function or method; the first and last method of a class use beforeFirstFunction and afterLastFunction'),
			'betweenFunctionsInInterface' => BlankLines::schema(1),
			'beforeFirstFunction' => BlankLines::schema(0)->description('Before a method that is the first member of its class'),
			'afterLastFunction' => BlankLines::schema(0)->description('After a method that is the last member of its class'),
			'afterClassBrace' => BlankLines::schema(0)
				->description('Before the first member, unless it is a method: then beforeFirstFunction applies'),
			'beforeClassBrace' => BlankLines::schema(0)
				->description('After the last member, unless it is a method: then afterLastFunction applies'),
			'betweenTraitUses' => BlankLines::schema(0),
			'afterTraitUses' => BlankLines::schema(1)
				->description('Before the member following the trait uses, unless it is a method: then betweenFunctions applies'),
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

			// the statements of a block, by their kind
			'before' => $counts()->default(['return' => [1, null]])
				->description('Blank lines before a statement of the kind, as a count or a range [min, max] with null for no bound; yield means a statement made of a yield expression'),
			'after' => $counts()->description('Blank lines after a statement of the kind, before the next statement'),
		]);
	}


	public function configure(array $options): void
	{
		foreach ($options as $name => $value) {
			$this->$name = $name === 'before' || $name === 'after'
				? array_map(BlankLines::count(...), $value)
				: BlankLines::count($value);
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
		$braces = [
			'openBrace' => [null, fn(Gap $gap) => ($gap->token->getNext()?->is('}') ?? false) ? null : $after],
			'closeBrace' => [$this->beforeBlockBrace === null ? null : Claim::blank($this->beforeBlockBrace), null],
		];
		foreach (self::Blocks as $class) {
			$claims[$class] = $braces;
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

		$declared = self::isDeclaration($stmt) ? $this->beforeDeclaration : null;
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
	 * Before a function declared outside a class, below the doc comment of a function or a class, else what
	 * the kind of the statement asks for; the two never answer for the same statement.
	 */
	private function beforeStatement(Node|Token $stmt, int $index): ?Claim
	{
		$blank = $stmt instanceof Statement\FunctionNode && $index > 0 ? $this->betweenFunctions : null;
		$below = $stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode ? $this->belowDocComment($stmt) : null;
		return $blank === null && $below === null
			? $this->byKind($stmt, $index - 1, $this->before)
			: new Claim(blank: $blank, blankBelowComment: $below);
	}


	/** After a function declared outside a class, else what the kind of the statement asks for. */
	private function afterStatement(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if ($stmt instanceof Statement\FunctionNode && $list instanceof NodeList) {
			$next = $list->getItems()[$index + 1] ?? null;
			return $next !== null && !$next instanceof Statement\FunctionNode && $this->betweenFunctions !== null
				? Claim::blank($this->betweenFunctions)
				: null;
		}

		return $this->byKind($stmt, $index + 1, $this->after);
	}


	/**
	 * The count the statement's kind asks for on the side of its neighbor at the index, when that is a statement
	 * of its own standing and not a declaration whose blank lines the options above own.
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
				$index === 0 => $this->beforeFirstFunction,
				$previous instanceof Member\TraitUseNode => 1, // a method right after `use` of a trait
				default => $this->betweenFunctionsOf($list),
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
			$next === null => $this->afterLastFunction,
			$next instanceof Member\MethodNode => null,
			default => $this->betweenFunctionsOf($list),
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
	private function betweenFunctionsOf(NodeList $members): int|array|null
	{
		return $members->parent instanceof Statement\InterfaceNode ? $this->betweenFunctionsInInterface : $this->betweenFunctions;
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


	private static function isDeclaration(StatementNode $stmt): bool
	{
		return $stmt instanceof ClassLikeNode || $stmt instanceof Statement\FunctionNode;
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

		foreach (array_slice($leading, 1, $at - 1) as $trivia) {
			if (!$trivia->isWhitespace()) {
				return false;
			}
		}

		return true;
	}
}
