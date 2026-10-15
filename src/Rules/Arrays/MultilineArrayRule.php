<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, Line, RuleInfo, Space, Stage, Style, Values};
use DressCode\Domains\Count;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode, SkippedArrayItemNode};
use PhpSyntax\Nodes\Expression\ArrayNode;
use function count;


/**
 * An array, or a destructuring, spread over lines stands in the shapes of `multiline.items.array` and
 * `multiline.edges.array`, `list()` in those of `multiline.items.list` and `multiline.edges.list`, the comma of
 * a skipped item standing for it; a list its author spread is written again only on the axis whose shape is not
 * accepted. An array of several items on one line wider than `multiline.maxWidth.array`, or on a line wider than the
 * line length of the style where no outer construct on the line is spread first and `file.lineLengthExcept` does not
 * name the place, is spread in the first shapes, and in `fill` its items without keys fill the lines evenly up to the
 * line length from `multiline.fillMinItems` of them. Whatever the shapes, the opening bracket stays on the line of the
 * code before it (an assignment, a return, a double arrow) and each comma on the line of its item, and any other array
 * kept on one line is left alone. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineArrayRule extends GapRule
{
	private const MaxWidth = 'multiline.maxWidth.array';

	/** @var array<string, ListLayout> place => its shapes */
	private array $layouts = [];

	/** @var array<string, int> place => from how many items it fills its lines */
	private array $fillMinItems = [];

	private ?int $maxWidth = null;


	public static function getDecisions(): array
	{
		$fill = 'Written as `fill`, the items without keys fill the lines evenly from `multiline.fillMinItems` of them, fewer standing one on a line';
		return [
			...ListLayout::createDecisions(
				'array',
				'item',
				'items',
				'The items of an array or of a destructuring in brackets spread over lines, by their author, for the width of their line or for `multiline.maxWidth.array`, the opening bracket staying on the line of the code before it',
				'the opening bracket',
				'the closing one',
				[$fill],
			),
			...ListLayout::createDecisions(
				'list',
				'item',
				'items',
				'The items of `list()` spread over lines, by their author or for the width of their line',
				'`(`',
				'`)`',
				[$fill],
			),
			new Decision(self::MaxWidth, new Count(1, range: false, words: [
				'none' => 'never spread for its width',
			]), 'The width from bracket to bracket over which an array of several items written on one line is spread over lines', kind: DecisionKind::Parameter, default: 'none'),
			new Decision(
				'multiline.fillMinItems.array',
				new Count(2, range: false),
				'From how many items without keys an array written in `fill` fills its lines',
				kind: DecisionKind::Parameter,
				default: 2,
			),
			new Decision(
				'multiline.fillMinItems.list',
				new Count(2, range: false),
				'From how many items without keys `list()` written in `fill` fills its lines',
				kind: DecisionKind::Parameter,
				default: 2,
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach (['array', 'list'] as $place) {
			$this->layouts[$place] = ListLayout::fromValues($values, $place);
			$this->fillMinItems[$place] = $values->get("multiline.fillMinItems.$place")->getCount()[0];
		}

		$width = $values->get(self::MaxWidth);
		$this->maxWidth = $width->content === 'none' ? null : $width->getCount()[0];
	}


	public function getClaims(): array
	{
		$claims = [
			'items:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null,
				null,
			],
			'items:separator' => [
				fn(Gap $gap) => $gap->token->getPrevious()?->is(['[', '(', ','])
					// the comma of a skipped item stands for the item, which has no token of its own
					? $this->decide($gap, $gap->token->parent?->parent)['items'][$gap->index ?? 0] ?? null
					: $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null,
				null,
			],
			'closeDelimiter' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
			'openDelimiter' => [
				fn(Gap $gap) => self::isHandedOver($gap->token) ? $this->decide($gap, $gap->token->parent)['open'] ?? null : null,
				null,
			],
		];
		return [ArrayNode::class => $claims, DestructuringNode::class => $claims];
	}


	/**
	 * What the rule asks of an array spread over lines, as `claimArray()` gives it; null for an array that stays as it is.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim, open: Claim}
	 */
	private function decide(Gap $gap, ?Node $array): ?array
	{
		return ($array instanceof ArrayNode || $array instanceof DestructuringNode) && !$array->items->isEmpty()
			? $gap->once($array, fn() => $this->claimArray($array, $gap))
			: null;
	}


	/**
	 * The claims of an array spread over lines with the reason: before each item, before the closing bracket, on the
	 * comma and on the opening bracket; null for an array that stays on its line, and for one to be packed by the width
	 * of lines not indented yet.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim, open: Claim}
	 */
	private function claimArray(ArrayNode|DestructuringNode $array, Gap $gap): ?array
	{
		$place = $array instanceof DestructuringNode && $array->listKeyword !== null ? 'list' : 'array';
		$layout = $this->layouts[$place];
		$style = $gap->style;
		$multiline = NodeHelpers::isMultiline($array->openDelimiter, $array->items->getItems(), $array->closeDelimiter);
		$because = match (true) {
			$multiline => 'the array spans several lines',
			$layout->items === [] => null,
			default => ($place === 'array' ? $this->findMaxWidthReason($array, $style) : null)
				?? NodeHelpers::findWidthReason($array, $gap),
		};
		[$itemShape, $edgeShape] = $multiline
			? $layout->findShapes($array->openDelimiter, $array->closeDelimiter)
			: $layout->getFirst();
		if (
			$because === null
			|| (!$multiline && $itemShape === ListLayout::Fill && !NodeHelpers::isLineInPlace($gap, $array->openDelimiter))
		) {
			return null;
		}

		$packed = $itemShape === ListLayout::Fill && !$multiline
			? $this->claimPacked($array, $style, $because, $this->fillMinItems[$place])
			: null;
		$claims = $layout->claim(
			$array->items->getItems(),
			$array->closeDelimiter,
			$itemShape === ListLayout::Fill && !$multiline && $packed === null ? ListLayout::PerLine : $itemShape,
			$edgeShape,
			$because,
			$packed,
			$multiline ? $layout->explainEdges($array->openDelimiter, $array->closeDelimiter, $edgeShape) : null,
			whole: !$multiline,
		);
		if ($claims !== null) {
			$claims['separator'] = new Claim(Space::None, line: Line::Same, decision: $claims['separator']->decision);
			$claims['open'] = new Claim(line: Line::Same, decision: $claims['separator']->decision);
		}

		return $claims;
	}


	/**
	 * Why an array of several items on one line is spread: it is wider than maxWidth, from its opening bracket to its
	 * closing one; an array of a single item has nothing to spread it into.
	 */
	private function findMaxWidthReason(ArrayNode|DestructuringNode $array, Style $style): ?string
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
	 * The claims before the items of an array spread for its width with at least the given count of items, filling its
	 * lines evenly; null for an array whose items stand one on a line, as one with a comment or with keys does, a pair
	 * being read one by one.
	 * @return ?list<Claim>
	 */
	private function claimPacked(ArrayNode|DestructuringNode $array, Style $style, string $because, int $minItems): ?array
	{
		$items = $array->items->getItems();
		if (
			$style->maxLineLength === null
			|| count($items) < $minItems
			|| !array_all(
				$items,
				fn($item) => ($item instanceof ArrayItemNode && $item->key === null) || $item instanceof SkippedArrayItemNode,
			)
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
