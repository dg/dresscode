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
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member;
use PhpSyntax\Nodes\MemberNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function array_slice, count;


/**
 * Blank lines around declarations: a fixed number between functions and methods (fewer in an interface),
 * another before the first member of a class and after the last one, none after the opening brace and
 * before the closing one, at most one between properties, constants and enum cases, and none between
 * a doc comment or an attribute and what it belongs to. Functions declared outside a class follow `betweenFunctions`
 * as well. Every count is a number, a range `[min, max]` with an open end as null, or null to leave the
 * place alone.
 */
#[RuleInfo(
	'dresscode/declaration-blank-lines',
	Stage::Formatting,
	description: 'Normalizes blank lines between declarations, around the class braces and after a doc comment',
)]
final class DeclarationBlankLinesRule extends GapRule implements ConfigurableRule
{
	private const ClassLikes = [
		Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
		AnonymousClassNode::class,
	];

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenFunctions = 2;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenFunctionsInInterface = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeFirst = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterLast = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterOpeningBrace = 0;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeClosingBrace = 0;

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


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'betweenFunctions' => BlankLines::schema(2)
				->description('Before and after a function or method; the first and last method of a class use beforeFirst and afterLast'),
			'betweenFunctionsInInterface' => BlankLines::schema(1),
			'beforeFirst' => BlankLines::schema(0)->description('Before a method that is the first member of its class'),
			'afterLast' => BlankLines::schema(0)->description('After a method that is the last member of its class'),
			'afterOpeningBrace' => BlankLines::schema(0)
				->description('Before the first member, unless it is a method: then beforeFirst applies'),
			'beforeClosingBrace' => BlankLines::schema(0)
				->description('After the last member, unless it is a method: then afterLast applies'),
			'betweenTraitUses' => BlankLines::schema(0),
			'afterTraitUses' => BlankLines::schema(1)
				->description('Before the member following the trait uses, unless it is a method: then betweenFunctions applies'),
			'betweenMembers' => BlankLines::schema([0, 1])
				->description('Between properties, constants and enum cases without a doc comment or attribute'),
			'beforeDocumentedMember' => BlankLines::schema(1)
				->description('Before a property, constant or enum case with a doc comment or an attribute'),
			'afterPhpDoc' => BlankLines::schema(0)
				->description('Between a doc comment or an attribute and the declaration it belongs to'),
		]);
	}


	public function configure(array $options): void
	{
		foreach ($options as $name => $value) {
			$this->$name = BlankLines::count($value);
		}
	}


	public function getClaims(): array
	{
		$claims = [
			'*' => [
				'statements:item' => [
					fn(Gap $gap) => $this->beforeStatement($gap->value, $gap->index ?? 0),
					fn(Gap $gap) => $this->afterFunction($gap->value, $gap->index ?? 0),
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
				'closeBrace' => [fn(Gap $gap) => $this->beforeClosingBrace($gap->token), null],
			];
		}

		return $claims;
	}


	/**
	 * Before a function declared outside a class, unless it is the first statement of its list, and below the
	 * doc comment of a function or a class.
	 */
	private function beforeStatement(Node|Token $stmt, int $index): ?Claim
	{
		$blank = $stmt instanceof Statement\FunctionNode && $index > 0 ? $this->betweenFunctions : null;
		$below = $stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode ? $this->belowDocComment($stmt) : null;
		return $blank === null && $below === null ? null : new Claim(blank: $blank, blankBelowComment: $below);
	}


	/** After a function declared outside a class, before a statement that is not a function. */
	private function afterFunction(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof Statement\FunctionNode || !$list instanceof NodeList) {
			return null;
		}

		$next = $list->getItems()[$index + 1] ?? null;
		return $next !== null && !$next instanceof Statement\FunctionNode && $this->betweenFunctions !== null
			? Claim::blank($this->betweenFunctions)
			: null;
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
				$index === 0 => $this->beforeFirst,
				$previous instanceof Member\TraitUseNode => 1, // a method right after `use` of a trait
				default => $this->betweenFunctionsOf($list),
			},
			$previous === null => $this->afterOpeningBrace,
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
			$next === null => $this->afterLast,
			$next instanceof Member\MethodNode => null,
			default => $this->betweenFunctionsOf($list),
		};
		return $count === null ? null : Claim::blank($count);
	}


	/** Before the closing brace of a class, unless a method stands last and claims the gap after itself. */
	private function beforeClosingBrace(Token $brace): ?Claim
	{
		$class = $brace->parent;
		$members = match (true) {
			$class instanceof Statement\ClassNode,
			$class instanceof Statement\InterfaceNode,
			$class instanceof Statement\TraitNode,
			$class instanceof Statement\EnumNode,
			$class instanceof AnonymousClassNode => $class->members->getItems(),
			default => [],
		};
		$last = $members[count($members) - 1] ?? null;
		return $last instanceof Member\MethodNode || $this->beforeClosingBrace === null
			? null
			: Claim::blank($this->beforeClosingBrace);
	}


	/**
	 * @param NodeList<MemberNode> $members
	 * @return int|array{int, ?int}|null
	 */
	private function betweenFunctionsOf(NodeList $members): int|array|null
	{
		return $members->parent instanceof Statement\InterfaceNode ? $this->betweenFunctionsInInterface : $this->betweenFunctions;
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
