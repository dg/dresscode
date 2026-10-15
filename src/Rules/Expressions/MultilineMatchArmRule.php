<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, Gap, GapRule, RuleInfo, Stage, Style, Values};
use DressCode\Domains\Words;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\{MatchArmNode, SeparatedNodeList};
use function count;


/**
 * The conditions of a `match` arm spread over lines by their author or on a line wider than the line length of the
 * style stand one on a line, the first where the arm begins and each comma on the line of its condition, and `=>`
 * with the result follows the last one; with `asWritten` the conditions spread by their author stand as they are,
 * and more than five on a line too wide fill the lines evenly up to the line length instead. An arm of a single
 * condition stays, and so does one on a line where `file.lineLengthExcept` names `matchArm`. Where the lines stand
 * is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineMatchArmRule extends GapRule
{
	private const AsWritten = 'asWritten';

	private string $shape;


	public static function getDecisions(): array
	{
		return [new Decision(
			'multiline.matchArmConditions',
			new Words([
				'perLine' => 'every condition on a line of its own, `=>` after the last one',
				self::AsWritten => 'the conditions stand as their author spread them and more than five spread for the width fill the lines, `=>` after the last one',
			]),
			'The conditions of a `match` arm spread over lines by their author or for the width of their line',
		)];
	}


	public function configure(Values $values): void
	{
		$this->shape = $values->get('multiline.matchArmConditions')->getWord();
	}


	public function getClaims(): array
	{
		return [MatchArmNode::class => [
			'values:item' => [
				fn(Gap $gap) => $gap->index ? $this->decide($gap, $gap->value->parent)['items'][$gap->index] ?? null : null,
				null,
			],
			'values:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['separator'] ?? null, null],
		]];
	}


	/**
	 * The claims of conditions spread over lines, before each condition and on the comma hugging one; null when they
	 * stay as they are. Decided once per pass about the list.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $list): ?array
	{
		return $list instanceof SeparatedNodeList
			? $gap->once($list, fn() => $this->claimConditions($list, $gap))
			: null;
	}


	/**
	 * What `decide()` gives: no claim before a condition its author placed with `asWritten`, the fill of the lines
	 * by `claimPacked()`, or a break before each.
	 * @param  SeparatedNodeList<covariant Node>  $list
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function claimConditions(SeparatedNodeList $list, Gap $gap): ?array
	{
		$frame = NodeHelpers::findSplitFrame($list);
		$because = $frame === null ? null : self::findReasonToBreak($list, $frame, $gap);
		if ($frame === null || $because === null) {
			return null;
		}

		$itemShape = $this->shape === self::AsWritten ? ListLayout::Fill : ListLayout::PerLine;
		$packed = !$frame[3] && $itemShape === ListLayout::Fill
			? self::claimPacked($list, $frame[1], $gap->style, $because)
			: null;
		return ListLayout::claim(
			$list->getItems(),
			null,
			!$frame[3] && $packed === null ? ListLayout::PerLine : $itemShape,
			null,
			$because,
			$packed,
		);
	}


	/**
	 * Why the conditions take lines of their own: they span lines, or they stand on a line wider than the line length
	 * of the style, no outer construct on the line is spread first and the line is indented.
	 * @param  SeparatedNodeList<covariant Node>  $list
	 * @param  array{string, Token, list<Token>, bool, bool}  $frame
	 */
	private static function findReasonToBreak(SeparatedNodeList $list, array $frame, Gap $gap): ?string
	{
		if ($frame[3]) {
			return 'the conditions span several lines';
		} elseif (!$gap->style->lineSplits->has($frame[0])) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($list, $frame[1], $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $frame[1])
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}


	/**
	 * The claims before the conditions of an arm spread for its width with more than five of them: a condition follows
	 * the one before on its line while the line fits into the line length, and begins the next one when it does not;
	 * null for an arm whose conditions stand one on a line, as one with a comment does.
	 * @param  SeparatedNodeList<covariant Node>  $list
	 * @return ?list<Claim>
	 */
	private static function claimPacked(SeparatedNodeList $list, Token $start, Style $style, string $because): ?array
	{
		$conditions = $list->getItems();
		if ($style->maxLineLength === null || count($conditions) <= NodeHelpers::MaxItemsOnOwnLines) {
			return null;
		}

		$indentation = Indentation::measure($start->getLineIndentation(), $style->toPhpSyntax());
		return NodeHelpers::packItems($conditions, $indentation, $style->maxLineLength, $because);
	}
}
