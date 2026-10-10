<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Domains\Count;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{CaseNode, CatchNode, ElseifNode, ElseNode, FinallyNode, PlainNodeList, Statement, StatementNode};
use PhpSyntax\Nodes\Expression\MatchNode;
use function count;


/**
 * How many blank lines stand inside the braces of a block: after the opening and before the closing one, between the
 * branches of an `if` or a `try`, and between the cases of a `switch`. Comments stay with the code below the blank lines.
 */
#[RuleInfo(Stage::Formatting)]
final class BlockBlankLinesRule extends GapRule
{
	private const Blocks = [Statement\BlockNode::class, Statement\SwitchNode::class, MatchNode::class];
	private const LastSetApart = 'lastSetApart';

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterOpening = null;

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeClosing = null;

	/** @var int|array{int, ?int}|self::LastSetApart|null */
	private int|array|string|null $betweenBranches = null;

	/** @var int|array{int, ?int}|self::LastSetApart|null */
	private int|array|string|null $betweenCases = null;

	private readonly BlankLineClaims $claims;


	public function __construct()
	{
		$this->claims = new BlankLineClaims;
	}


	public static function getDecisions(): array
	{
		$lastSetApart = new Count(words: ['lastStatementSetApart' => 'one where the last statement of the branch stands apart, which would otherwise read as belonging to the next one, the others kept']);
		return [
			new Decision('blankLines.afterBlockOpeningBrace', Domain::blankLines(), 'After the opening brace of a block'),
			new Decision('blankLines.beforeBlockClosingBrace', Domain::blankLines(), 'Before the closing brace of a block'),
			new Decision('blankLines.betweenBranches', $lastSetApart, 'Before the closing brace of a branch of `if` or `try` that `else`, `elseif`, `catch` or `finally` follows, in place of `beforeBlockClosingBrace`'),
			new Decision('blankLines.betweenCases', $lastSetApart, 'Before a `case` or `default` of a `switch` that follows a case with statements, below a comment right under those statements'),
		];
	}


	public function configure(Values $values): void
	{
		$this->afterOpening = BlankLineClaims::readCount($values->get('blankLines.afterBlockOpeningBrace'));
		$this->beforeClosing = BlankLineClaims::readCount($values->get('blankLines.beforeBlockClosingBrace'));
		$readSetApart = fn(string $key) => !$values->isKept("blankLines.$key") && $values->get("blankLines.$key")->hasWord()
			? self::LastSetApart
			: BlankLineClaims::readCount($values->get("blankLines.$key"));
		$this->betweenBranches = $readSetApart('betweenBranches');
		$this->betweenCases = $readSetApart('betweenCases');
	}


	public function getClaims(): array
	{
		// an empty block has one gap, not two, and it belongs to the brace that closes it
		$after = $this->claims->claim($this->afterOpening, 'afterBlockOpeningBrace');
		$before = $this->claims->claim($this->beforeClosing, 'beforeBlockClosingBrace');
		$braces = [
			'openBrace' => [null, $after === null ? null : fn(Gap $gap) => ($gap->token->getNext()?->is('}') ?? false) ? null : $after],
			'closeBrace' => [$before, null],
		];
		$claims = [];
		foreach (self::Blocks as $class) {
			$claims[$class] = $braces;
		}

		if ($this->betweenBranches !== null) {
			$between = $this->claims->claim($this->betweenBranches === self::LastSetApart ? 1 : $this->betweenBranches, 'betweenBranches');
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

		$comment = $block->closeBrace->findLastLeadingCommentIndex();
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
