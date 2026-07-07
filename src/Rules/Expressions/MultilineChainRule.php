<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\{MethodCallNode, PropertyFetchNode};


/**
 * A chain of method calls and property accesses split over lines has every link on a line of its own, the
 * first one on the line after the expression the chain starts from; with `sameLine`, the links before the first
 * one that begins a line stay on the first line and only the links after it take lines of their own. A chain of two
 * method calls or more on a line wider than the line length of the style is split so, with `sameLine` from the link
 * after its first method call, unless an outer construct on the line is spread first or `file.lineLengthExcept` names
 * `chain`; a chain of one call or of property accesses alone stays. Where the lines stand is the matter of
 * `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineChainRule extends GapRule
{
	private const OwnLine = 'ownLine';
	private const SameLine = 'sameLine';
	private const FirstLinks = 'multiline.chainFirstLinks';

	private string $firstLinks = self::OwnLine;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'multiline.chain',
				new Words(['perLine' => 'every link on a line of its own']),
				'The links of a chain of method calls and property accesses spread over lines by its author, or for the width of its line where it has two method calls or more',
			),
			new Decision(self::FirstLinks, new Words([
				self::OwnLine => 'the first link begins a line too',
				self::SameLine => 'the links before the first one beginning a line stay on the line the chain starts on',
			]), 'Where the links stand that come before the first one beginning a line', kind: DecisionKind::Parameter, default: self::OwnLine),
		];
	}


	public function configure(Values $values): void
	{
		$this->firstLinks = $values->get(self::FirstLinks)->getWord();
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the chain spans several lines');
		$link = ['operator' => [
			fn(Gap $gap) => $this->claimForWidth($gap, $gap->token->parent) ?? ($this->isMultiline($gap->token->parent) ? $break : null),
			null,
		]];
		return [MethodCallNode::class => $link, PropertyFetchNode::class => $link];
	}


	/**
	 * The break before a link of a chain on a line too wide: before every link, or with `sameLine` before every link
	 * after the first method call, the object and the links up to that call staying on the first line.
	 */
	private function claimForWidth(Gap $gap, ?Node $link): ?Claim
	{
		if (!$link instanceof MethodCallNode && !$link instanceof PropertyFetchNode) {
			return null;
		}

		$chain = $link;
		while (
			($chain->parent instanceof MethodCallNode || $chain->parent instanceof PropertyFetchNode)
			&& $chain->parent->object === $chain
		) {
			$chain = $chain->parent;
		}

		$because = $gap->once($chain, fn() => self::findWidthReason($chain, $gap));
		return $because !== null && ($this->firstLinks === self::OwnLine || self::followsCall($link))
			? new Claim(line: Line::Next, because: $because)
			: null;
	}


	/**
	 * Why a chain of two method calls or more on one line is spread: its line is wider than the line length of the
	 * style and no outer construct on the line is spread first; measured once the line is indented.
	 */
	private static function findWidthReason(MethodCallNode|PropertyFetchNode $chain, Gap $gap): ?string
	{
		$frame = NodeHelpers::findSplitFrame($chain);
		if ($frame === null || $frame[3] || !$gap->style->lineSplits->has($frame[0])) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($chain, $frame[1], $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $frame[1])
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}


	/** Whether a method call stands among the links before this one. */
	private static function followsCall(MethodCallNode|PropertyFetchNode $link): bool
	{
		for ($node = $link->object; $node instanceof MethodCallNode || $node instanceof PropertyFetchNode; $node = $node->object) {
			if ($node instanceof MethodCallNode) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Whether the link takes a line of its own: some link of its chain begins a line, or with `sameLine`, the
	 * link itself or one before it does.
	 */
	private function isMultiline(?Node $link): bool
	{
		if (!$link instanceof MethodCallNode && !$link instanceof PropertyFetchNode) {
			return false;
		}

		// up to the outermost link unless the links before this one decide, then down through the links
		while (
			$this->firstLinks === self::OwnLine
			&& (
				$link->parent instanceof MethodCallNode
				|| $link->parent instanceof PropertyFetchNode
			)
			&& $link->parent->object === $link
		) {
			$link = $link->parent;
		}

		for ($node = $link; $node instanceof MethodCallNode || $node instanceof PropertyFetchNode; $node = $node->object) {
			if ($node->operator->startsLine()) {
				return true;
			}
		}

		return false;
	}
}
