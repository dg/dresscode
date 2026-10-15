<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, RuleInfo, Stage, Style, Values};
use DressCode\Domains\Count;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\{MatchArmNode, SeparatedNodeList};
use function count;


/**
 * The conditions of a `match` arm spread over lines by their author or on a line wider than the line length of the
 * style stand in the shape of `multiline.items.matchCondition`, the first where the arm begins and each comma on the
 * line of its condition, and `=>` with the result follows the last one; conditions on a line too wide are written in
 * the first shape, in `fill` filling the lines evenly up to the line length from `multiline.fillMinItems.matchCondition`
 * of them. An arm of a single condition stays, and so does one on a line where `file.lineLengthExcept` names
 * `matchCondition`. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineMatchArmRule extends GapRule
{
	private const FillMinItems = 'multiline.fillMinItems.matchCondition';

	private ListLayout $layout;
	private int $fillMinItems = 2;


	public static function getDecisions(): array
	{
		return [
			...ListLayout::createDecisions(
				'matchCondition',
				'condition',
				'conditions',
				'The conditions of a `match` arm spread over lines by their author or for the width of their line, `=>` after the last one',
				notes: ['Written as `fill`, the conditions fill the lines evenly from `multiline.fillMinItems.matchCondition` of them, fewer standing one on a line'],
			),
			new Decision(
				self::FillMinItems,
				new Count(2, range: false),
				'From how many conditions an arm written in `fill` fills its lines',
				kind: DecisionKind::Parameter,
				default: 2,
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'matchCondition');
		$this->fillMinItems = $values->get(self::FillMinItems)->getCount()[0];
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
	 * What `decide()` gives: no claim before a condition its author placed where `fill` is accepted, the fill of the
	 * lines by `claimPacked()`, or a break before each.
	 * @param  SeparatedNodeList<covariant Node>  $list
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function claimConditions(SeparatedNodeList $list, Gap $gap): ?array
	{
		$frame = NodeHelpers::findSplitFrame($list);
		if ($frame === null || $this->layout->items === []) {
			return null;
		}

		$multiline = $frame[3];
		$because = $multiline ? 'the conditions span several lines' : NodeHelpers::findWidthReason($list, $gap);
		if ($because === null) {
			return null;
		}

		[$itemShape] = $multiline ? $this->layout->findShapes($frame[1], null) : $this->layout->getFirst();
		$packed = !$multiline && $itemShape === ListLayout::Fill
			? $this->claimPacked($list, $frame[1], $gap->style, $because)
			: null;
		return $this->layout->claim(
			$list->getItems(),
			null,
			!$multiline && $packed === null ? ListLayout::PerLine : $itemShape,
			null,
			$because,
			$packed,
			whole: !$multiline,
		);
	}


	/**
	 * The claims before the conditions of an arm spread for its width with at least `multiline.fillMinItems` of them:
	 * a condition follows the one before on its line while the line fits into the line length, and begins the next one
	 * when it does not; null for an arm whose conditions stand one on a line, as one with a comment does.
	 * @param  SeparatedNodeList<covariant Node>  $list
	 * @return ?list<Claim>
	 */
	private function claimPacked(SeparatedNodeList $list, Token $start, Style $style, string $because): ?array
	{
		$conditions = $list->getItems();
		if ($style->maxLineLength === null || count($conditions) < $this->fillMinItems) {
			return null;
		}

		$indentation = Indentation::measure($start->getLineIndentation(), $style->toPhpSyntax());
		return NodeHelpers::packItems($conditions, $indentation, $style->maxLineLength, $because);
	}
}
