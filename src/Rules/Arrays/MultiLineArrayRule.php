<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage, Style};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Indentation, Node, Token, TokenKind};
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\Expression\ArrayNode;
use function count;


/**
 * An array spread over lines has every item on a line of its own and the closing bracket on a line of its
 * own; `keep` leaves that frame as it is, so an array whose first item stands on the line of the opening
 * bracket keeps the closing one where it is too. An array of several items on one line wider than maxWidth
 * is spread in either shape, and with keep more than five items fill its lines up to the line length
 * instead. Whatever the frame, the opening bracket stays on the line of the code before it (an assignment,
 * a return, a double arrow) and each comma on the line of its item, and any other array kept on one line is
 * left alone. Where the lines stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-array',
	Stage::Formatting,
	description: 'Spreads a multi-line or too wide array over lines, an item on each or several filling a line, the opening bracket on the line before',
)]
final class MultiLineArrayRule extends GapRule implements ConfigurableRule
{
	private const PerLine = 'perLine';
	private const Keep = 'keep';

	/** an array spread for its width with more items than this lets them fill its lines with keep, not a line each */
	private const MaxItemsOnOwnLines = 5;

	private string $shape = self::PerLine;
	private ?int $maxWidth = null;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'shape' => Expect::anyOf(self::PerLine, self::Keep)->default(self::PerLine)
				->description('perLine gives every item a line of its own, keep lets the items of an array spread by its author stand as they are'),
			'maxWidth' => Expect::int()->min(1)->nullable()
				->description('An array of several items on one line wider than this, from its opening bracket to its closing one, is spread over lines, more than five items filling each line up to the line length with keep; null leaves the width to the author'),
		]);
	}


	public function configure(array $options): void
	{
		$this->shape = $options['shape'];
		$this->maxWidth = $options['maxWidth'];
	}


	public function getClaims(): array
	{
		return [ArrayNode::class => [
			'items:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent, frame: true)[1][$gap->index ?? 0] ?? null,
				null,
			],
			'items:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)[3] ?? null, null],
			// whether the closing bracket starts a line is the frame of the array, which keep leaves alone
			'closeDelimiter' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent, frame: true)[2] ?? null, null],
			'openDelimiter' => [
				fn(Gap $gap) => self::follows($gap->token) ? $this->decide($gap, $gap->token->parent)[4] ?? null : null,
				null,
			],
		]];
	}


	/**
	 * What the rule asks of an array spread over lines, as decideNow() gives it; null for an array that stays as it
	 * is, and for the frame of an array its author spread, which keep leaves alone.
	 * @return ?array{bool, list<Claim>, Claim, Claim, Claim}
	 */
	private function decide(Gap $gap, ?Node $array, bool $frame = false): ?array
	{
		if (!$array instanceof ArrayNode || $array->items->isEmpty()) {
			return null;
		}

		$decision = $gap->once($array, fn() => $this->decideNow($array, $gap));
		return $decision === null || ($frame && $decision[0] && $this->shape === self::Keep) ? null : $decision;
	}


	/**
	 * Whether the array spreads over lines already, and the claims of a spread array with the reason: before each
	 * item, before the closing bracket, on the comma and on the opening bracket; null for an array that stays on its
	 * line, and for one to be packed by the width of lines not indented yet.
	 * @return ?array{bool, list<Claim>, Claim, Claim, Claim}
	 */
	private function decideNow(ArrayNode $array, Gap $gap): ?array
	{
		$style = $gap->style;
		$broken = self::isBrokenNow($array);
		$because = $broken ? 'the array spans several lines' : $this->measureNow($array, $style);
		if ($because === null || (!$broken && $this->shape === self::Keep && !NodeHelpers::isLineInPlace($gap, $array->openDelimiter))) {
			return null;
		}

		$break = new Claim(line: Line::Next, because: $because);
		$packed = !$broken && $this->shape === self::Keep ? self::packNow($array, $style, $because) : null;
		return [
			$broken,
			$packed ?? array_fill(0, count($array->items->getItems()), $break),
			$break,
			new Claim(Space::None, line: Line::Same, because: $because),
			new Claim(line: Line::Same, because: $because),
		];
	}


	/**
	 * Whether the array spreads over lines: a line break after the opening bracket, an item starting a line,
	 * or the closing bracket doing so. A comment after the bracket ends its line without breaking the array.
	 */
	private static function isBrokenNow(ArrayNode $array): bool
	{
		$open = $array->openDelimiter;
		if (($open->getTrailingSpace() === null && !$open->hasComment()) || $array->closeDelimiter->startsLine()) {
			return true;
		}

		return array_any($array->items->getItems(), fn(Node $item) => $item->getFirstToken()?->startsLine() === true);
	}


	/**
	 * Why an array of several items on one line is spread: it is wider than maxWidth, from its opening bracket to its
	 * closing one; an array of a single item has nothing to spread it into.
	 */
	private function measureNow(ArrayNode $array, Style $style): ?string
	{
		$open = $array->openDelimiter;
		$close = $array->closeDelimiter;
		if ($this->maxWidth === null || count($array->items->getItems()) < 2 || $open->getLine() !== $close->getLine()) {
			return null;
		}

		$phpSyntax = $style->toPhpSyntax();
		$from = $open->getVisualColumn($phpSyntax);
		$to = $close->getVisualColumn($phpSyntax);
		$width = $from === null || $to === null ? 0 : $to - $from + 1;
		return $width > $this->maxWidth ? "the array is $width characters wide" : null;
	}


	/**
	 * The claims before the items of an array spread for its width with more than five items: an item follows the
	 * one before on its line while the line fits into the line length, and begins the next one when it does not;
	 * null for an array whose items stand one on a line, as one with a comment does.
	 * @return ?list<Claim>
	 */
	private static function packNow(ArrayNode $array, Style $style, string $because): ?array
	{
		$items = $array->items->getItems();
		if ($style->lineLength === null || count($items) <= self::MaxItemsOnOwnLines) {
			return null;
		}

		$break = new Claim(line: Line::Next, because: $because);
		$follow = new Claim(line: Line::Same, because: $because);
		$indentation = Indentation::width($array->openDelimiter->getLineIndentation() . $style->indent, $style->toPhpSyntax());
		$claims = [];
		$column = 0;
		foreach ($items as $i => $item) {
			$width = $item instanceof ArrayItemNode ? self::measureItem($item) : null;
			if ($width === null) {
				return null;
			}

			$width++; // the comma after it
			if ($i > 0 && $column + 1 + $width <= $style->lineLength) {
				$claims[] = $follow;
				$column += 1 + $width;
			} else {
				$claims[] = $break;
				$column = $indentation + $width;
			}
		}

		return $claims;
	}


	/** The width of the item, which stands on the line of its array; null for an item holding a comment. */
	private static function measureItem(ArrayItemNode $item): ?int
	{
		$last = $item->getLastToken();
		$width = 0;
		for ($token = $item->getFirstToken(); $token !== null; $token = $token->getNext()) {
			if ($token->hasComment()) {
				return null;
			}

			$width += mb_strlen($token->text);
			if ($token === $last) {
				return $width;
			}

			$width += $token->getTrailingSpace() === '' ? 0 : 1;
		}

		return null;
	}


	/**
	 * Whether the bracket follows code it belongs on the line of: what hands the array over (an assignment,
	 * a double arrow, a return, a yield); an array that is an item of a list is placed by the rule of the list.
	 */
	private static function follows(Token $bracket): bool
	{
		$previous = $bracket->getPrevious();
		return $previous !== null
			&& ($previous->is(TokenKind::DoubleArrow, TokenKind::Return, TokenKind::Yield, TokenKind::Echo)
				|| (str_ends_with($previous->text, '=') && !$previous->is('==', '===', '!=', '!==', '<=', '>=', '<>')));
	}
}
