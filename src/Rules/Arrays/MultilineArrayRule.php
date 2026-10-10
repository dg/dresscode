<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, Line, RuleInfo, Space, Stage, Style, Values};
use DressCode\Domains\{Count, Words};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\Expression\ArrayNode;
use function count;


/**
 * An array spread over lines has every item on a line of its own and the closing bracket on a line of its
 * own; with `asWritten` the items stand as their author spread them and the closing bracket mirrors the opening
 * one, following the last item where the first item follows the opening bracket. An array of several items on one
 * line wider than `multiline.split.array` is spread in either shape, and with `asWritten` more than five items
 * fill its lines up to the line length instead. Whatever the frame, the opening bracket stays on the line of the code
 * before it (an assignment, a return, a double arrow) and each comma on the line of its item, and any other array
 * kept on one line is left alone. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, analyses: [IndentationPlan::class])]
final class MultilineArrayRule extends GapRule
{
	private const Shape = 'multiline.shape.array';
	private const MaxWidth = 'multiline.split.array';
	private const PerLine = 'perLine';
	private const AsWritten = 'asWritten';

	/** an array spread for its width with more items than this lets them fill its lines with `asWritten`, not a line each */
	private const MaxItemsOnOwnLines = 5;

	private string $shape;
	private ?int $maxWidth = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Shape, new Words([
				self::PerLine => 'every item on a line of its own and the closing bracket on a line of its own',
				self::AsWritten => 'the items stand as their author spread them and the closing bracket begins a line where the first item does',
			]), 'The items of an array spread over lines, by its author or for its width, the opening bracket staying on the line of the code before it'),
			new Decision(self::MaxWidth, new Count(1, range: false, words: [
				'none' => 'never spread for its width',
			]), 'The width from bracket to bracket over which an array of several items written on one line is spread over lines, more than five items filling its lines where `shape.array` is `asWritten`', kind: DecisionKind::Parameter, default: 'none'),
		];
	}


	public function configure(Values $values): void
	{
		$this->shape = $values->get(self::Shape)->getWord();
		$width = $values->get(self::MaxWidth);
		$this->maxWidth = $width->content === 'none' ? null : $width->getCount()[0];
	}


	public function getClaims(): array
	{
		return [ArrayNode::class => [
			'items:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null,
				null,
			],
			'items:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			'closeDelimiter' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
			'openDelimiter' => [
				fn(Gap $gap) => self::isHandedOver($gap->token) ? $this->decide($gap, $gap->token->parent)['open'] ?? null : null,
				null,
			],
		]];
	}


	/**
	 * What the rule asks of an array spread over lines, as `claimArray()` gives it; null for an array that stays as it is.
	 * @return ?array{items: list<Claim>, close: ?Claim, separator: Claim, open: Claim}
	 */
	private function decide(Gap $gap, ?Node $array): ?array
	{
		return $array instanceof ArrayNode && !$array->items->isEmpty()
			? $gap->once($array, fn() => $this->claimArray($array, $gap))
			: null;
	}


	/**
	 * The claims of an array spread over lines with the reason: before each item, before the closing bracket, on the
	 * comma and on the opening bracket; null for an array that stays on its line, and for one to be packed by the width
	 * of lines not indented yet.
	 * @return ?array{items: list<Claim>, close: ?Claim, separator: Claim, open: Claim}
	 */
	private function claimArray(ArrayNode $array, Gap $gap): ?array
	{
		$style = $gap->style;
		$multiline = NodeHelpers::isMultiline($array->openDelimiter, $array->items->getItems(), $array->closeDelimiter);
		$because = $multiline ? 'the array spans several lines' : $this->findWidthReason($array, $style);
		if (
			$because === null
			|| (!$multiline && $this->shape === self::AsWritten && !NodeHelpers::isLineInPlace($gap, $array->openDelimiter))
		) {
			return null;
		}

		$break = new Claim(line: Line::Next, because: $because);
		$asWritten = $this->shape === self::AsWritten;
		$packed = !$multiline && $asWritten ? self::claimPacked($array, $style, $because) : null;
		return [
			'items' => $multiline && $asWritten ? [] : ($packed ?? array_fill(0, count($array->items->getItems()), $break)),
			'close' => $multiline && $asWritten ? self::closeAsWritten($array) : $break,
			'separator' => new Claim(Space::None, line: Line::Same),
			'open' => new Claim(line: Line::Same),
		];
	}


	/**
	 * The claim before the closing bracket of an array its author spread over lines: it follows the last item where
	 * the first one follows the opening bracket, and begins a line where the first one does.
	 */
	private static function closeAsWritten(ArrayNode $array): ?Claim
	{
		$close = $array->closeDelimiter;
		return match (true) {
			$array->items->getItems()[0]->getFirstToken()?->startsLine() === true => new Claim(line: Line::Next, because: 'the first item begins a line'),
			$close->getPrevious()?->hasCommentUpTo($close) === true => null,
			default => new Claim(line: Line::Same, because: 'the first item follows the opening bracket'),
		};
	}


	/**
	 * Why an array of several items on one line is spread: it is wider than maxWidth, from its opening bracket to its
	 * closing one; an array of a single item has nothing to spread it into.
	 */
	private function findWidthReason(ArrayNode $array, Style $style): ?string
	{
		$open = $array->openDelimiter;
		$close = $array->closeDelimiter;
		if ($this->maxWidth === null || count($array->items->getItems()) < 2 || $open->getCurrentLine() !== $close->getCurrentLine()) {
			return null;
		}

		$phpSyntax = $style->toPhpSyntax();
		$from = $open->getVisualColumn($phpSyntax);
		$to = $close->getVisualColumn($phpSyntax);
		$width = $from === null || $to === null ? 0 : $to - $from + 1;
		return $width > $this->maxWidth ? "the array is $width characters wide, more than $this->maxWidth" : null;
	}


	/**
	 * The claims before the items of an array spread for its width with more than five items: an item follows the
	 * one before on its line while the line fits into the line length, and begins the next one when it does not;
	 * null for an array whose items stand one on a line, as one with a comment does.
	 * @return ?list<Claim>
	 */
	private static function claimPacked(ArrayNode $array, Style $style, string $because): ?array
	{
		$items = $array->items->getItems();
		if (
			$style->maxLineLength === null
			|| count($items) <= self::MaxItemsOnOwnLines
			|| !array_all($items, fn($item) => $item instanceof ArrayItemNode)
		) {
			return null;
		}

		$indentation = Indentation::measure($array->openDelimiter->getLineIndentation() . $style->indent, $style->toPhpSyntax());
		return NodeHelpers::packItems($items, $indentation, $style->maxLineLength, $because);
	}


	/**
	 * Whether the bracket follows code it belongs on the line of: what hands the array over (an assignment,
	 * a double arrow, a return, a yield); an array that is an item of a list is placed by the rule of the list.
	 */
	private static function isHandedOver(Token $bracket): bool
	{
		$previous = $bracket->getPrevious();
		return $previous !== null
			&& ($previous->is([Token::DoubleArrow, Token::Return, Token::Yield, Token::Echo])
				|| (str_ends_with($previous->text, '=') && !$previous->is(['==', '===', '!=', '!==', '<=', '>=', '<>'])));
	}
}
