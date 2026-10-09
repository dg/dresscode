<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\{Style, Values, Violation};
use PhpSyntax\{Indentation, LayoutRole, Node, Nodes, Token};
use PhpSyntax\Nodes\Member\PropertyHookNode;
use PhpSyntax\Nodes\Statement\{BlockNode, NamespaceNode};
use function count;


/**
 * The indentation every line of the file is given by the construct it continues, as the decisions `indentation.*`
 * of the run place it: what a construct holds one level below the line the construct begins on, what closes or
 * continues it at that line, the level counted from the level the construct itself was given. A line nothing
 * places, the run not deciding it, keeps what it has, and the lines below count from that. A rule deciding by the
 * width of a line asks `isLineInPlace()` and waits until the line has its indentation, so that the decision does not
 * depend on how the line happened to be indented.
 */
final class IndentationPlan
{
	public const Unit = 'indentation.unit';
	public const Binary = 'indentation.binaryOperator';
	public const Ternary = 'indentation.ternary.level';
	public const TernaryBelowCondition = 'indentation.ternary.belowCondition';
	public const SwitchCase = 'indentation.switchCase';
	public const Chain = 'indentation.chain';

	/** the decisions of a tree of the core the plan is made by, which every catalogue knows */
	public const Decisions = [self::Unit, self::Binary, self::Ternary, self::TernaryBelowCondition, self::SwitchCase, self::Chain];

	/** @var array<int, string>  line => the indentation it is given, or has where nothing governs it */
	private array $lines = [];

	/** @var array<int, Token>  line => the token opening it */
	private array $openers = [];

	/** @var array<int, array{Token, string, string, string, ?Token, string}>  line => its opener, the indentation it and a comment above it are given, what it is, the opener of the line it follows, and the decision it stands under */
	private array $placements = [];


	/**
	 * One walk over the tokens in their order: the line a construct begins on is placed before any line
	 * continuing it, so the level of the latter counts from what the former was given. A level of null leaves the
	 * lines of that role as they are.
	 */
	public function __construct(
		Nodes\FileNode $file,
		private readonly Style $style,
		private readonly bool $placesLines = true,
		private readonly ?int $binary = 0,
		private readonly ?int $ternary = 1,
		private readonly bool $ternaryBelowCondition = false,
		private readonly ?int $switchCase = 1,
		private readonly ?string $chain = 'flat',
	) {
		$previous = null;
		foreach ($file->getTokens() as $token) {
			// what Indentation::opensLine() asks, with the previous token at hand: most tokens carry no trivia at all
			$trailing = $previous === null ? [] : $previous->trailingTrivia;
			$last = $trailing[count($trailing) - 1] ?? null;
			$opens = $last !== null && $last->isLineEnding() && !$last->inInterpolation;
			foreach ($opens ? [] : $token->leadingTrivia as $trivia) {
				if ($trivia->isLineEnding() && !$trivia->inInterpolation) {
					$opens = true;
					break;
				}
			}

			if ($opens) {
				$this->openers[$token->getCurrentLine() ?? 0] = $token;
				$this->place($token);
			} elseif ($previous === null || preg_match('~[\r\n]$~', $previous->text) === 1) {
				// a line the text opens (inline HTML, a heredoc, a close tag) is a fact the lines below count from
				$this->lines[$token->getCurrentLine() ?? 0] = Indentation::normalize($token->getIndentation(), $style->toPhpSyntax());
			}

			$previous = $token;
		}
	}


	/**
	 * The factory a registry of analyses builds the plan with: the levels the decisions the run reports and repairs
	 * give, a decision the run is narrowed away from leaving its lines as they are.
	 * @return \Closure(Nodes\FileNode): self
	 */
	public static function createFactory(Values $values, Style $style): \Closure
	{
		$count = fn(string $path) => $values->isSelected($path) ? $values->get($path)->getCount()[0] : null;
		$unit = $values->isSelected(self::Unit);
		$binary = $count(self::Binary);
		$ternary = $count(self::Ternary);
		$ternaryBelowCondition = $values->get(self::TernaryBelowCondition)->getWord() === 'stepped';
		$switchCase = $count(self::SwitchCase);
		$chain = $values->isSelected(self::Chain) ? $values->get(self::Chain)->getWord() : null;
		return fn(Nodes\FileNode $file) => new self($file, $style, $unit, $binary, $ternary, $ternaryBelowCondition, $switchCase, $chain);
	}


	/**
	 * The lines the plan places, by line: the token opening it, the indentation it and a comment above it are given,
	 * what it is, the opener of the line its level counts from, and the decision that places it.
	 * @return array<int, array{Token, string, string, string, ?Token, string}>
	 */
	public function getPlacements(): array
	{
		return $this->placements;
	}


	/** Whether the line the token stands on has the indentation the plan gives it, or the plan places it not at all. */
	public function isLineInPlace(Token $token): bool
	{
		$placement = $this->placements[$token->getCurrentLine() ?? 0] ?? null;
		return $placement === null || Indentation::matches($placement[0], $placement[1], $placement[2]);
	}


	/** Places a token that opens a line by the construct it continues. */
	private function place(Token $token): void
	{
		$style = $this->style;
		$line = $token->getCurrentLine() ?? 0;
		$found = Indentation::findOwner($token);
		if ($found === null) { // the first token of the file
			$this->lines[$line] = '';
			$this->placements[$line] = [$token, '', '', 'a statement', null, self::Unit];
			return;
		}

		[$owner, $child, $role, $item] = [$found->node, $found->child, $found->role, $found->item];
		// the content of a body counts from the line its structure begins on, wherever the brace stands
		$structure = $owner instanceof BlockNode && !$owner->parent instanceof Nodes\PlainNodeList ? $owner->parent : $owner;
		$structure = $role === LayoutRole::Operator ? self::findChainStart($structure) : $structure;
		$first = $structure?->getFirstToken() ?? $token;
		// a line the text opens, the end of a heredoc, gives no level to count from
		$last = $this->ternaryBelowCondition && $role === LayoutRole::Branch && $owner instanceof Nodes\Expression\TernaryNode
			? $owner->condition->getLastToken()
			: null;
		$from = $last !== null && isset($this->openers[$last->getCurrentLine() ?? 0]) ? $last : $first;
		$base = $this->lines[$from->getCurrentLine() ?? 0] ?? Indentation::normalize($from->getLineIndentation(), $style->toPhpSyntax());
		$level = match ($role) {
			LayoutRole::Content => $this->placesLines ? ($owner instanceof NamespaceNode && $owner->openBrace === null ? 0 : 1) : null,
			LayoutRole::Anchor, LayoutRole::Closer => $this->placesLines ? 0 : null,
			LayoutRole::Body => $this->placesLines ? ($child instanceof BlockNode ? 0 : 1) : null,
			LayoutRole::Operator => $this->binary === null ? null : max($this->binary, self::getMinimumLevel($first)),
			LayoutRole::Branch => $this->ternary === null
				? null
				: max($this->ternary, $from->getCurrentLine() === $first->getCurrentLine() ? self::getMinimumLevel($first) : 1),
			LayoutRole::Link => match ($this->chain) {
				null => null,
				'nested' => $this->measureNestingLevel($owner, $token, $base),
				default => 1,
			},
			// @phpstan-ignore match.alwaysTrue (a newer PhpSyntax may add a role, which the default leaves as it is written)
			LayoutRole::Case => $this->switchCase,
			default => null,
		};
		if ($level === null) { // left alone by the options: what the line has is what the lines below count from
			$this->lines[$line] = Indentation::normalize($token->getIndentation(), $style->toPhpSyntax());
			return;
		}

		$indentation = $base . $style->getIndentation($level);
		$this->lines[$line] = $indentation;
		// a comment above a closing bracket belongs to the content before it, unless the bracket is followed
		// by the next branch of a non-empty structure, which the comment then introduces; a comment above
		// a later case belongs to the statements of the previous one
		$commentIndentation = match (true) {
			$role === LayoutRole::Closer && !(self::continuesStructure($token) && !$token->getPrevious()?->is('{')),
			$role === LayoutRole::Case && !self::isFirstItem($child, $token)
				=> $indentation . $style->indent,
			default => $indentation,
		};
		// the line is placed by what the line of its construct was given, so it follows that line wherever it went
		$this->placements[$line] = [
			$token,
			$indentation,
			$commentIndentation,
			self::describe($role, $item, $token),
			$this->openers[$from->getCurrentLine() ?? 0] ?? null,
			match ($role) {
				LayoutRole::Operator => self::Binary,
				LayoutRole::Branch => self::Ternary,
				LayoutRole::Link => self::Chain,
				LayoutRole::Case => self::SwitchCase,
				default => self::Unit,
			},
		];
	}


	/**
	 * The outermost expression of a chain of one right-associative operator. `$a ?? $b ?? $c` is two nested
	 * nodes, the second operator belonging to the inner one, but the reader sees one chain and every operator
	 * of it counts from the line the first operand stands on; a chain the source parenthesizes has a node
	 * between the two and is left alone.
	 */
	private static function findChainStart(?Node $node): ?Node
	{
		while (
			$node instanceof Nodes\Expression\BinaryOpNode
			&& $node->parent instanceof Nodes\Expression\BinaryOpNode
			&& $node->parent->right === $node
			&& $node->parent->operator->text === $node->operator->text
		) {
			$node = $node->parent;
		}

		return $node;
	}


	/**
	 * The level a line continuing an expression may not go below: an expression sharing its line with what
	 * holds it has no line of its own to line up with, and one beginning its statement stands at the level
	 * of a statement, where a continuation would read as the next one.
	 */
	private static function getMinimumLevel(Token $first): int
	{
		return !$first->startsLine() || Indentation::opensStatement($first) ? 1 : 0;
	}


	/** Whether the next token continues the structure the token closes: `else`, `elseif`, `catch`, `finally`, the `while` of a `do`. */
	private static function continuesStructure(Token $token): bool
	{
		$next = $token->getNext();
		return $next?->is([Token::Else, Token::Elseif, Token::Catch, Token::Finally])
			|| ($next?->is(Token::While) && $next->parent instanceof Nodes\Statement\DoWhileNode);
	}


	private static function isFirstItem(Node|Token $list, Token $token): bool
	{
		$first = $list instanceof Nodes\PlainNodeList ? $list->getChildren()[0] ?? null : null;
		return $first instanceof Node && $first->getFirstToken() === $token;
	}


	/**
	 * A link of a chain may stand one level deeper than the link before it, or one level shallower; the first
	 * link and a step of more than one level are pulled into place.
	 */
	private function measureNestingLevel(Node $owner, Token $operator, string $base): int
	{
		$phpSyntax = $this->style->toPhpSyntax();
		$unit = Indentation::measure($this->style->indent, $phpSyntax);
		$deeper = Indentation::measure($operator->getIndentation(), $phpSyntax) - Indentation::measure($base, $phpSyntax);
		$actual = $deeper > 0 ? intdiv($deeper, $unit) : 0;
		$previous = 0;
		for ($link = $owner instanceof Nodes\ExpressionNode ? self::findInnerLink($owner) : null; $link !== null; $link = self::findInnerLink($link)) {
			$before = self::findLinkOperator($link);
			if ($before?->startsLine()) {
				$given = $this->lines[$before->getCurrentLine() ?? 0] ?? $base;
				$previous = intdiv(Indentation::measure($given, $phpSyntax) - Indentation::measure($base, $phpSyntax), $unit);
				break;
			}
		}

		return min(max($actual, $previous - 1, 1), $previous + 1);
	}


	/** The expression the node is applied to when it is a link of a chain, null when the node begins one. */
	private static function findInnerLink(Nodes\ExpressionNode $node): ?Nodes\ExpressionNode
	{
		return match (true) {
			$node instanceof Nodes\Expression\MethodCallNode, $node instanceof Nodes\Expression\PropertyFetchNode => $node->object,
			// a pipeline is a chain of calls, every other binary operator a computation
			$node instanceof Nodes\Expression\BinaryOpNode => $node->operator->is(Token::Pipe) ? $node->left : null,
			$node instanceof Nodes\Expression\ArrayAccessNode => $node->expression,
			$node instanceof Nodes\Expression\FunctionCallNode => $node->name instanceof Nodes\ExpressionNode ? $node->name : null,
			$node instanceof Nodes\Expression\StaticMethodCallNode,
			$node instanceof Nodes\Expression\StaticPropertyFetchNode,
			$node instanceof Nodes\Expression\ClassConstantFetchNode
				=> $node->class instanceof Nodes\ExpressionNode ? $node->class : null,
			default => null,
		};
	}


	/** The operator that links the node to the expression it is applied to, null where nothing links it. */
	private static function findLinkOperator(Nodes\ExpressionNode $node): ?Token
	{
		return match (true) {
			$node instanceof Nodes\Expression\MethodCallNode, $node instanceof Nodes\Expression\PropertyFetchNode => $node->operator,
			$node instanceof Nodes\Expression\BinaryOpNode => $node->operator->is(Token::Pipe) ? $node->operator : null,
			default => null,
		};
	}


	private static function describe(LayoutRole $role, Node|Token $item, Token $token): string
	{
		return match (true) {
			$role === LayoutRole::Closer => ctype_alpha($token->text) ? "the `$token->text` keyword" : 'a closing ' . match ($token->text) {
				'}' => 'brace',
				')' => 'parenthesis',
				default => 'bracket',
			},
			$role === LayoutRole::Link => 'a chained call',
			$role === LayoutRole::Operator, $role === LayoutRole::Branch => 'a continued expression',
			$item instanceof BlockNode, $token->is('{') => 'an opening brace',
			$item instanceof Nodes\CaseNode => 'a case',
			$item instanceof Nodes\StatementNode => 'a statement',
			$item instanceof Nodes\MemberNode => 'a member',
			$item instanceof Nodes\ParameterNode => 'a parameter',
			$item instanceof Nodes\ArgumentNode, $item instanceof Nodes\ArgumentPlaceholderNode, $item instanceof Nodes\VariadicPlaceholderNode => 'an argument',
			$item instanceof Nodes\ArrayItemNode, $item instanceof Nodes\SkippedArrayItemNode => 'an array item',
			$item instanceof Nodes\MatchArmNode => 'a match arm',
			$item instanceof Nodes\AttributeGroupNode, $item instanceof Nodes\AttributeNode => 'an attribute',
			$item instanceof PropertyHookNode => 'a property hook',
			$token->is(Token::CloseTag) => 'a close tag',
			$item instanceof Token => ctype_alpha($token->text) ? "the `$token->text` keyword" : 'the ' . Violation::formatCode($token->text) . ' operator',
			$item instanceof Nodes\ExpressionNode => 'a continued expression',
			$item->parent instanceof Nodes\PlainNodeList, $item->parent instanceof Nodes\SeparatedNodeList => 'a list item',
			default => 'a continuation line',
		};
	}
}
