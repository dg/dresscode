<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, Gap, GapRule, RuleInfo, Stage};
use DressCode\Domains\Words;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\{AnonymousClassNode, SeparatedNodeList};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode};


/**
 * The interfaces a class or an enum implements, or an interface extends, spread over lines by their author or on
 * a line wider than the line length of the style, stand one on a line, the first on the line after the keyword, and
 * each comma on the line of its interface, as PER Coding Style shapes them; a list of a single interface stays, and
 * so does one on a line where an outer construct is spread first or `file.lineLengthExcept` names `implements`.
 * Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineImplementsRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'multiline.implements',
			new Words(['perLine' => 'every interface on a line of its own, the first on the line after the keyword']),
			'The interfaces a class or an enum implements, or an interface extends, spread over lines by their author or for the width of their line',
		)];
	}


	public function getClaims(): array
	{
		$claims = fn(string $slot) => [
			"$slot:item" => [fn(Gap $gap) => self::decide($gap, $gap->value->parent)['items'][$gap->index ?? 0] ?? null, null],
			"$slot:separator" => [fn(Gap $gap) => self::decide($gap, $gap->token->parent)['separator'] ?? null, null],
		];
		return [
			ClassNode::class => $claims('implements'),
			EnumNode::class => $claims('implements'),
			AnonymousClassNode::class => $claims('implements'),
			InterfaceNode::class => $claims('extends'),
		];
	}


	/**
	 * The claims of interfaces taking lines of their own, as `ListLayout::claim()` gives them; null when they stay as
	 * they are. Decided once per pass about the list.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private static function decide(Gap $gap, ?Node $list): ?array
	{
		if (!$list instanceof SeparatedNodeList) {
			return null;
		}

		return $gap->once($list, function () use ($list, $gap): ?array {
			$because = self::findReasonToBreak($list, $gap);
			return $because === null
				? null
				: ListLayout::claim($list->getItems(), null, ListLayout::PerLine, ListLayout::Apart, $because);
		});
	}


	/**
	 * Why the interfaces take lines of their own: the list spans lines, or it stands on a line wider than the line
	 * length of the style, no outer construct on the line is spread first and the line is indented.
	 * @param  SeparatedNodeList<covariant Node>  $list
	 */
	private static function findReasonToBreak(SeparatedNodeList $list, Gap $gap): ?string
	{
		$frame = NodeHelpers::findSplitFrame($list);
		if ($frame === null) {
			return null;
		} elseif ($frame[3]) {
			return 'the interfaces span several lines';
		} elseif (!$gap->style->lineSplits->has($frame[0])) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($list, $frame[1], $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $frame[1])
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}
}
