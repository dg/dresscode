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
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


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
final class DeclarationBlankLinesRule extends Rule implements ConfigurableRule
{
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
	private int|array|null $afterPhpdoc = 0;


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
			'afterPhpdoc' => BlankLines::schema(0)
				->description('Between a doc comment or an attribute and the declaration it belongs to'),
		]);
	}


	public function configure(array $options): void
	{
		foreach ($options as $name => $value) {
			$this->$name = $value;
		}
	}


	public function getVisitedTypes(): array
	{
		return [ClassLikeNode::class, Statement\FunctionNode::class, Member\MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof Statement\FunctionNode || $node instanceof Member\MethodNode) {
			$this->checkFunction($node, $context);
			$this->checkAfterPhpdoc($node, $context);
		} elseif (
			$node instanceof Statement\ClassNode
			|| $node instanceof Statement\InterfaceNode
			|| $node instanceof Statement\TraitNode
			|| $node instanceof Statement\EnumNode
			|| $node instanceof AnonymousClassNode
		) {
			$this->checkMembers($node, $context);
			$this->checkAfterPhpdoc($node, $context);
		}
	}


	private function checkFunction(Statement\FunctionNode|Member\MethodNode $node, RuleContext $context): void
	{
		$list = $node->parent;
		if (!$list instanceof NodeList) {
			return;
		}

		$items = $list->getItems();
		$index = $list->indexOf($node);
		$inClass = $node instanceof Member\MethodNode;
		$lines = $list->parent instanceof Statement\InterfaceNode ? $this->betweenFunctionsInInterface : $this->betweenFunctions;

		$first = $node->getFirstToken();
		if ($first?->startsLine() && ($index > 0 || $inClass)) {
			$expected = match (true) {
				$index === 0 => $this->beforeFirst,
				($items[$index - 1] ?? null) instanceof Member\TraitUseNode => 1, // a method right after `use` of a trait
				default => $lines,
			};
			if ($expected !== null) {
				BlankLines::ensure($first, $expected, 'before the function', $context);
			}
		}

		$next = $items[$index + 1] ?? null;
		$after = $next?->getFirstToken() ?? ($inClass ? $node->getLastToken()?->getNext() : null);
		$expected = $next === null ? $this->afterLast : $lines;
		if (
			$expected !== null
			&& $after?->startsLine()
			&& !$next instanceof Statement\FunctionNode
			&& !$next instanceof Member\MethodNode
			&& !$after->hasComment() // a comment before what follows owns the blank lines above itself
		) {
			BlankLines::ensure($after, $expected, 'after the function', $context);
		}
	}


	private function checkMembers(
		Statement\ClassNode|Statement\InterfaceNode|Statement\TraitNode|Statement\EnumNode|AnonymousClassNode $node,
		RuleContext $context,
	): void
	{
		$members = $node->members->getItems();
		$previous = null;
		foreach ($members as $member) {
			$first = $member->getFirstToken();
			if ($first?->startsLine() && !$member instanceof Member\MethodNode) {
				[$expected, $what] = $this->expectation($member, $previous);
				if ($expected !== null && $what !== null) {
					BlankLines::ensure($first, $expected, $what, $context);
				}
			}

			if (!$member instanceof Member\MethodNode) {
				$this->checkAfterPhpdoc($member, $context);
			}

			$previous = $member;
		}

		if (
			$this->beforeClosingBrace !== null
			&& $node->closeBrace->startsLine()
			&& !$previous instanceof Member\MethodNode
			&& !$node->closeBrace->hasComment() // a trailing comment owns the blank lines above itself
		) {
			BlankLines::ensure($node->closeBrace, $this->beforeClosingBrace, 'before the closing brace of the class', $context);
		}
	}


	/**
	 * @return array{int|array{int, ?int}|null, ?string}  expected count and what for the message
	 */
	private function expectation(Node $member, ?Node $previous): array
	{
		$documented = $member instanceof Member\PropertyNode || $member instanceof Member\ClassConstNode || $member instanceof Member\EnumCaseNode
			? !$member->attributes->isEmpty() || $member->getDocComment() !== null
			: false;
		return match (true) {
			$previous === null => [$this->afterOpeningBrace, 'after the opening brace of the class'],
			$member instanceof Member\TraitUseNode => $previous instanceof Member\TraitUseNode
				? [$this->betweenTraitUses, 'between trait uses']
				: [null, null],
			$previous instanceof Member\TraitUseNode => [$this->afterTraitUses, 'after the trait uses'],
			$previous instanceof Member\MethodNode => [null, null],
			$documented => [$this->beforeDocumentedMember, 'before a documented member'],
			default => [$this->betweenMembers, 'between members'],
		};
	}


	/**
	 * The blank lines between the doc comment or the last attribute and the first token of the declaration
	 * proper: the doc comment sits in the leading trivia of that token, an attribute is a node before it.
	 */
	private function checkAfterPhpdoc(Node $node, RuleContext $context): void
	{
		$expected = $this->afterPhpdoc;
		$attributes = $node instanceof Statement\ClassNode || $node instanceof Statement\InterfaceNode || $node instanceof Statement\TraitNode
			|| $node instanceof Statement\EnumNode || $node instanceof AnonymousClassNode || $node instanceof Statement\FunctionNode
			|| $node instanceof Member\MethodNode || $node instanceof Member\PropertyNode || $node instanceof Member\ClassConstNode
			|| $node instanceof Member\EnumCaseNode
			? $node->attributes
			: null;
		$token = $node->getFirstToken();
		if ($expected === null || $token === null || !$token->startsLine()) {
			return;
		}

		$after = $attributes === null || $attributes->isEmpty() ? null : $attributes->getLastToken()?->getNext();
		if ($after?->startsLine()) {
			BlankLines::ensure($after, $expected, 'after the attribute', $context);
		}

		$leading = $token->leadingTrivia;
		$at = null;
		foreach ($leading as $i => $trivia) {
			if ($trivia->kind === TriviaKind::DocComment) {
				$at = $i;
			}
		}

		if (
			$at === null
			|| ($leading[$at + 1] ?? null)?->kind !== TriviaKind::EndOfLine
			|| self::isFileHeader($leading, $at)
		) {
			return;
		}

		$blank = 0;
		while (($leading[$at + 2 + $blank] ?? null)?->kind === TriviaKind::EndOfLine) {
			$blank++;
		}

		[$min, $max] = is_int($expected) ? [$expected, $expected] : $expected;
		if ($blank >= $min && ($max === null || $blank <= $max)) {
			return;
		}

		if (!$context->report($token, BlankLines::describe($expected, 'after the doc comment', $blank), trivia: $leading[$at + 1])) {
			return;
		}

		$count = $blank < $min ? $min : (int) $max;
		$eol = new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol);
		$token->setLeadingTrivia([
			...array_slice($leading, 0, $at + 2),
			...array_fill(0, $count, $eol),
			...array_slice($leading, $at + 2 + $blank),
		]);
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
