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
 * of its argument, `fillPlain` filling the lines where every argument is plain, joining them where they fit on the
 * line of the call, and giving each a line otherwise; a list
 * its author spread is written again only on the axis whose shape is not accepted, and in `fill` its lines are filled
 * again where one is wider than the line length. A call on one line is spread in the first shapes,
 * in `fill` its arguments filling the lines evenly, when its line is wider than the line length of the style, measured
 * as the code of the line without a comment after it, unless an outer construct on the line is spread first,
 * `file.lineLengthExcept` names it, or its sole argument is a string, which is a message to leave whole; with
 * `multiline.spreadItem: sole` a sole argument that is a call, `new` or an array spreads in its place, the parentheses
 * staying together, with `last` the last one does so after the arguments before it, and a call so spread by its author
 * stays whatever the shapes. Where the lines stand is the matter of `IndentationRule`.
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
				fillPlain: true,
			),
			new Decision('multiline.spreadItem', new Words([
				'never' => 'the call spreads its arguments',
				'sole' => 'a sole argument that is a call, `new` or an array spreads in the parentheses, `foo(bar(` and `))` together',
				'last' => 'as `sole`, and so does the last argument after those before it where the line fits up to its bracket, `foo($a, [` and `])` together',
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
		$decide = fn(Gap $gap, ?Node $list) => self::isCallable($list)
			? ['items' => [$callable], 'close' => $callable]
			: $this->decide($gap, $list);
		return [
			ArgumentListNode::class => ListLayout::createClaims('items', 'closeParen', $decide),
			UnsetNode::class => ListLayout::createClaims('variables', 'closeParen', $decide),
			IssetNode::class => ListLayout::createClaims('variables', 'closeParen', $decide),
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

		return $this->layout->forItems($items->getItems())->decide(
			$gap,
			$list,
			$list->openParen,
			$items->getItems(),
			$list->closeParen,
			'the arguments span several lines',
			fn() => self::findWidthReason($list, $gap),
		);
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
