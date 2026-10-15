<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\{Claim, Decision, Line, Space, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use function count;


/**
 * The shapes of a list spread over lines, on two axes: how its items stand, `perLine` every one on a line of its own or
 * `fill` sharing lines, which accepts them one on a line too, and where its edges stand, `apart` with the items beginning
 * on the line after the opening token and the closing one on a line of its own, `attached` with the items beginning on
 * the line of the opening token and the closing one following the last item, `closingApart` with the items on the line
 * of the opening token and the closing one on a line of its own. Each axis accepts a list of shapes, the first of them
 * the one written, or none, which leaves the axis as it is; a list without a closing token knows the side of the opening
 * one alone.
 * @internal
 */
final readonly class ListLayout
{
	public const PerLine = 'perLine';
	public const Fill = 'fill';
	public const Apart = 'apart';
	public const Attached = 'attached';
	public const ClosingApart = 'closingApart';


	public function __construct(
		/** @var list<string> */
		public array $items,
		/** @var list<string> */
		public array $edges,
		/** the decision the claims of the items are made for, null for the only requirement of the rule */
		public ?string $itemDecision = null,
		/** the decision the claims of the edges are made for, null for the only requirement of the rule */
		public ?string $edgeDecision = null,
	) {
	}


	/**
	 * The requirements of a place, `multiline.items.<place>` and, where something opens the list, `multiline.edges.<place>`,
	 * the shapes said in the words of the place: an item, the items, what opens them and what closes them.
	 * @param  list<string>  $notes  of the items
	 * @return list<Decision>
	 */
	public static function createDecisions(
		string $place,
		string $item,
		string $items,
		string $description,
		?string $open = null,
		?string $close = null,
		array $notes = [],
		bool $fill = true,
	): array
	{
		$decisions = [new Decision(
			"multiline.items.$place",
			new Words([self::PerLine => "every $item on a line of its own"]
				+ ($fill ? [self::Fill => "the $items may share lines, one on a line passing too"] : []), tolerance: true),
			$description,
			$notes,
		)];
		if ($open !== null) {
			$decisions[] = new Decision(
				"multiline.edges.$place",
				new Words([
					self::Apart => "the first $item on the line after $open" . ($close === null ? '' : ", $close on a line of its own"),
					self::Attached => "the first $item on the line of $open" . ($close === null ? '' : ", $close right after the last one"),
				] + ($close === null
					? []
					: [self::ClosingApart => "the first $item on the line of $open, $close on a line of its own"]), tolerance: true),
				"Where the $items spread over lines begin against $open" . ($close === null ? '' : " and where $close stands"),
			);
		}

		return $decisions;
	}


	/** The shapes of the place as `multiline.items.<place>` and `multiline.edges.<place>` give them, none where kept. */
	public static function fromValues(Values $values, string $place): self
	{
		$items = "multiline.items.$place";
		$edges = "multiline.edges.$place";
		return new self(
			$values->isKept($items) ? [] : $values->get($items)->getWords(),
			$values->has($edges) && !$values->isKept($edges) ? $values->get($edges)->getWords() : [],
			$items,
			$edges,
		);
	}


	/**
	 * The shapes a list its author spread over lines stands in, the items and the edges, null for an axis left as it is:
	 * the items `fill` where it is accepted, which accepts anything, and `perLine` otherwise; the edges the shape the author
	 * wrote where it is accepted, else the first accepted shape keeping the side of the opening token the author wrote, or
	 * the first one.
	 * @return array{?string, ?string}
	 */
	public function findShapes(Token $open, ?Token $close): array
	{
		$edges = self::findEdges($open, $close);
		$attachedStart = $edges === self::Attached || $edges === self::ClosingApart;
		return [
			match (true) {
				$this->items === [] => null,
				in_array(self::Fill, $this->items, true) => self::Fill,
				default => self::PerLine,
			},
			match (true) {
				$this->edges === [] => null,
				in_array($edges, $this->edges, true) => $edges,
				default => array_find(
					$this->edges,
					fn(string $shape) => ($shape === self::Attached || $shape === self::ClosingApart) === $attachedStart,
				) ?? $this->edges[0],
			},
		];
	}


	/**
	 * Why the edges of a list its author spread are written in the shape: the side of the opening token the author wrote,
	 * where the edges accept more shapes than one and the shape keeps that side and changes the closing one; null
	 * otherwise.
	 */
	public function explainEdges(Token $open, ?Token $close, ?string $shape): ?string
	{
		$written = self::findEdges($open, $close);
		$attached = fn(?string $shape) => $shape === self::Attached || $shape === self::ClosingApart;
		return $shape === null || count($this->edges) < 2 || $shape === $written || $attached($shape) !== $attached($written)
			? null
			: ($attached($shape) ? 'the first item follows the opening bracket' : 'the first item begins a line');
	}


	/**
	 * The shapes a list spread for its width is written in, the items and the edges, the first of each.
	 * @return array{?string, ?string}
	 */
	public function getFirst(): array
	{
		return [$this->items[0] ?? null, $this->edges[0] ?? null];
	}


	/**
	 * Where the edges of a list spread over lines stand, read from the token after the opening one and the closing token,
	 * which a list without one leaves out; null for the items beginning on a line of their own and the closing token
	 * following them.
	 */
	public static function findEdges(Token $open, ?Token $close): ?string
	{
		$start = $open->getNext()?->startsLine() === true;
		return match (true) {
			$close === null => $start ? self::Apart : self::Attached,
			$start => $close->startsLine() ? self::Apart : null,
			default => $close->startsLine() ? self::ClosingApart : self::Attached,
		};
	}


	/**
	 * The claims of a list written in the shapes, the items and the edges, null for an axis to leave as it is:
	 * before each item, before the closing token and on each comma, which stays on the line of its item; null where both
	 * axes stay. The items of `fill` take the claims `$packed` gives them, the lines they fill, or stay where it is null.
	 * A token that would follow a comment ending its line keeps the line. The claims of the edges give `$edgeBecause`
	 * where there is one, and each claim is made for the decision of its axis, the comma for that of the items; a list
	 * spread whole, for its width or a property of it, is the matter of its items alone.
	 * @param  list<Node>  $items
	 * @param  ?list<Claim>  $packed
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	public function claim(
		array $items,
		?Token $close,
		?string $itemShape,
		?string $edgeShape,
		string $because,
		?array $packed = null,
		?string $edgeBecause = null,
		bool $whole = false,
	): ?array
	{
		if (($itemShape === null && $edgeShape === null) || $items === []) {
			return null;
		}

		$item = $this->items === [] ? $this->edgeDecision : $this->itemDecision ?? $this->edgeDecision;
		// where the items are written again too, the edges go with them, so that a line breaking for both is one violation
		$rewritten = $whole || $packed !== null || ($itemShape === self::PerLine && array_any(
			array_slice($items, 1),
			fn(Node $node) => $node->getFirstToken()?->startsLine() === false,
		));
		$edge = $rewritten ? $item : $this->edgeDecision ?? $item;
		$break = new Claim(line: Line::Next, because: $because, decision: $this->itemDecision);
		$edgeBreak = new Claim(line: Line::Next, because: $edgeBecause ?? $because, decision: $edge);
		$follow = fn(?Token $token) => $token === null || $token->getPrevious()?->hasCommentUpTo($token) === true
			? null
			: new Claim(line: Line::Same, because: $edgeBecause ?? $because, decision: $edge);
		$claims = [];
		foreach ($items as $i => $node) {
			$claims[] = match (true) {
				$i === 0 => match ($edgeShape) {
					self::Apart => $edgeBreak,
					self::Attached, self::ClosingApart => $follow($node->getFirstToken()),
					default => null,
				},
				$itemShape === self::PerLine => $break,
				$itemShape === self::Fill && $this->itemDecision !== null => ($packed[$i] ?? null)?->withDecision($this->itemDecision),
				$itemShape === self::Fill => $packed[$i] ?? null,
				default => null,
			};
		}

		return [
			'items' => $claims,
			'close' => match ($edgeShape) {
				self::Apart, self::ClosingApart => $close === null ? null : $edgeBreak,
				self::Attached => $follow($close),
				default => null,
			},
			'separator' => new Claim(Space::None, line: Line::Same, because: $because, decision: $item),
		];
	}
}
