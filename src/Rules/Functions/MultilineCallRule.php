<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Node;
use PhpSyntax\Nodes\{ArgumentListNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * The arguments of a call, of `new` or of an attribute, and the variables of `unset()` and `isset()`, spread over
 * several lines each take a line of their own, or with `frame` only the first one, the others sharing lines as their
 * author put them; the closing parenthesis stands on a line of its own and each comma on the line of its argument.
 * A call on one line is spread so when its line is wider than the line length of the style, measured as the code of
 * the line without a comment after it, unless an outer construct on the line is spread first, `file.lineLengthExcept`
 * names it, or its sole argument is a string, which is a message to leave whole; with `multiline.callSoleArgument:
 * hugged` a sole argument that is a call, `new` or an array spreads in its place, the parentheses staying together.
 * Where the lines stand is the matter of `IndentationRule`. The parentheses of a first-class callable, `strlen(...)`,
 * stay on one line.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineCallRule extends GapRule
{
	private const PerLine = 'perLine';
	private const Frame = 'frame';

	private string $shape = self::PerLine;


	public static function getDecisions(): array
	{
		return [new Decision('multiline.call', new Words([
			self::PerLine => 'every argument on a line of its own',
			self::Frame => 'only the parentheses on lines of their own',
		]), 'The arguments of a call, of `new` or of an attribute spread over lines by its author, which is one where an argument or the closing parenthesis begins a line, or for the width of its line, the closing parenthesis then standing on a line of its own and each comma on the line of its argument', notes: ['A sole argument that is a string, a message, is not spread for its width']),
			new Decision('multiline.callSoleArgument', new Words([
				'hugged' => 'a sole argument that is a call, `new` or an array spreads in the parentheses, `foo(bar(` and `))` together',
				'ownLine' => 'a sole argument takes a line of its own as any argument',
			]), 'Where a call too wide whose sole argument is a call, `new` or an array keeps it', kind: DecisionKind::Parameter, default: 'ownLine')];
	}


	public function configure(Values $values): void
	{
		$this->shape = $values->get('multiline.call')->getWord();
	}


	public function getClaims(): array
	{
		$callable = new Claim(line: Line::Same, because: 'a first-class callable has no arguments');
		$decide = fn(Gap $gap, ?Node $list) => self::isCallable($list) ? $callable : self::claimsToBreak($gap, $list)[0] ?? null;
		$perLine = $this->shape === self::PerLine;
		$claims = fn(string $items) => [
			"$items:item" => [fn(Gap $gap) => $perLine || $gap->index === 0 ? $decide($gap, $gap->value->parent?->parent) : null, null],
			"$items:separator" => [fn(Gap $gap) => self::claimsToBreak($gap, $gap->token->parent?->parent)[1] ?? null, null],
			'closeParen' => [fn(Gap $gap) => $decide($gap, $gap->token->parent), null],
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
	 * The claims of arguments spread over lines, the break before an argument or the closing parenthesis and the
	 * comma hugging one, with the reason; null when they stay as they are. Decided once per pass about the call,
	 * from the shape it has at its first gap.
	 * @return ?array{Claim, Claim}
	 */
	private static function claimsToBreak(Gap $gap, ?Node $list): ?array
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
			$because = NodeHelpers::isMultiline($list->openParen, $items->getItems(), $list->closeParen)
				? 'the arguments span several lines'
				: self::findWidthReason($list, $gap);
			return $because === null
				? null
				: [new Claim(line: Line::Next, because: $because), new Claim(Space::None, line: Line::Same, because: $because)];
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
