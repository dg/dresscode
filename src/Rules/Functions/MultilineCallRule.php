<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\{ArgumentListNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * The arguments of a call, of `new` or of an attribute, and the variables of `unset()` and `isset()`, spread over
 * several lines stand in the shapes of `multiline.items.argument` and `multiline.edges.argument`, each comma on the line
 * of its argument; a list its author spread is written again only on the axis whose shape is not accepted. A call on one
 * line is spread in the first shapes when its line is wider than the line length of the style, measured as the code of
 * the line without a comment after it, unless an outer construct on the line is spread first, `file.lineLengthExcept`
 * names it, or its sole argument is a string, which is a message to leave whole; with `multiline.spreadItem: sole`
 * a sole argument that is a call, `new` or an array spreads in its place, the parentheses staying together, and
 * a call so spread by its author stays whatever the shapes. Where the lines stand is the matter of `IndentationRule`.
 * The parentheses of a first-class callable, `strlen(...)`, stay on one line.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineCallRule extends GapRule
{
	private ListLayout $layout;


	public static function getDecisions(): array
	{
		return [
			...ListLayout::createDecisions(
				'argument',
				'argument',
				'arguments',
				'The arguments of a call, of `new` or of an attribute and the variables of `isset()` and `unset()` spread over lines by their author, which is one where an argument or the closing parenthesis begins a line, or for the width of their line, each comma on the line of its argument',
				'`(`',
				'`)`',
				['A sole argument that is a string, a message, is not spread for its width'],
			),
			new Decision('multiline.spreadItem', new Words([
				'never' => 'the call spreads its arguments',
				'sole' => 'a sole argument that is a call, `new` or an array spreads in the parentheses, `foo(bar(` and `))` together',
			]), 'What a call too wide spreads where an argument could spread in its place; one so spread by its author stays whatever it says', kind: DecisionKind::Parameter, default: 'never'),
		];
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'argument');
	}


	public function getClaims(): array
	{
		$callable = new Claim(line: Line::Same, because: 'a first-class callable has no arguments', decision: 'multiline.items.argument');
		$item = fn(Gap $gap, ?Node $list) => self::isCallable($list)
			? $callable
			: $this->decide($gap, $list)['items'][$gap->index ?? 0] ?? null;
		$close = fn(Gap $gap, ?Node $list) => self::isCallable($list) ? $callable : $this->decide($gap, $list)['close'] ?? null;
		$claims = fn(string $items) => [
			"$items:item" => [fn(Gap $gap) => $item($gap, $gap->value->parent?->parent), null],
			"$items:separator" => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			'closeParen' => [fn(Gap $gap) => $close($gap, $gap->token->parent), null],
		];
		return [
			ArgumentListNode::class => $claims('items'),
			UnsetNode::class => $claims('variables'),
			IssetNode::class => $claims('variables'),
		];
	}


	/** Whether the parentheses are those of a first-class callable, `strlen(...)`. */
	private static function isCallable(?Node $list): bool
	{
		return $list instanceof ArgumentListNode && ($list->items->getItems()[0] ?? null) instanceof VariadicPlaceholderNode;
	}


	/**
	 * The claims of arguments spread over lines, as `ListLayout::claim()` gives them; null when they stay as they are.
	 * Decided once per pass about the call, from the shape it has at its first gap.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $list): ?array
	{
		$items = match (true) {
			$list instanceof ArgumentListNode => $list->items,
			$list instanceof UnsetNode, $list instanceof IssetNode => $list->variables,
			default => null,
		};
		if ($items === null || $items->isEmpty()) {
			return null;
		}

		return $gap->once($list, function () use ($list, $items, $gap): ?array {
			$multiline = NodeHelpers::isMultiline($list->openParen, $items->getItems(), $list->closeParen);
			$because = $multiline ? 'the arguments span several lines' : self::findWidthReason($list, $gap);
			[$itemShape, $edgeShape] = $multiline
				? $this->layout->findShapes($list->openParen, $list->closeParen)
				: $this->layout->getFirst();
			return $because === null
				? null
				: $this->layout->claim($items->getItems(), $list->closeParen, $itemShape, $edgeShape, $because, whole: !$multiline);
		});
	}


	/**
	 * Why a call on one line is spread: its line is wider than the line length of the style and no outer construct
	 * on the line is spread first; measured once the line is indented.
	 */
	private static function findWidthReason(ArgumentListNode|UnsetNode|IssetNode $list, Gap $gap): ?string
	{
		// the width first, then what the call is: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($list, $list->openParen, $gap);
		$frame = $width === null ? null : NodeHelpers::findSplitFrame($list);
		return $frame !== null
			&& !$frame[4]
			&& $gap->style->lineSplits->has($frame[0])
			&& (!$list instanceof ArgumentListNode || NodeHelpers::findHuggedFrame($list, $gap->style) === null)
			&& NodeHelpers::isLineInPlace($gap, $list->openParen)
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}
}
