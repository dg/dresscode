<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\{MethodCallNode, PropertyFetchNode};


/**
 * A chain of method calls and property accesses split over lines has every link on a line of its own in `perLine` of
 * `multiline.items.chain`, and stays as its author split it where `fill` is accepted; with `multiline.edges.chain`
 * `apart` the first link stands on the line after the expression the chain starts from, with `attached` the links
 * before the first one that begins a line stay on the first line. A chain of two method calls or more on a line wider
 * than the line length of the style is split so, with `attached` from the link after its first method call, unless an
 * outer construct on the line is spread first or `file.lineLengthExcept` names `chain`; a chain of one call or of
 * property accesses alone stays. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineChainRule extends GapRule
{
	private const OwnLine = ListLayout::Apart;

	private string $firstLinks = self::OwnLine;
	private ListLayout $layout;


	public static function getDecisions(): array
	{
		return ListLayout::createDecisions(
			'chain',
			'link',
			'links',
			'The links of a chain of method calls and property accesses spread over lines by its author, or for the width of its line where it has two method calls or more',
			'the object',
		);
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'chain');
		$this->firstLinks = $this->layout->edges[0] ?? self::OwnLine;
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the chain spans several lines', decision: $this->layout->itemDecision);
		$strict = $this->layout->items !== [] && !in_array(ListLayout::Fill, $this->layout->items, true);
		$link = ['operator' => [
			fn(Gap $gap) => $this->claimForWidth($gap, $gap->token->parent)
				?? ($strict && $this->isMultiline($gap->token->parent) ? $break : null),
			null,
		]];
		return [MethodCallNode::class => $link, PropertyFetchNode::class => $link];
	}


	/**
	 * The break before a link of a chain on a line too wide: before every link, or with `attached` before every link
	 * after the first method call, the object and the links up to that call staying on the first line.
	 */
	private function claimForWidth(Gap $gap, ?Node $link): ?Claim
	{
		if (
			(!$link instanceof MethodCallNode && !$link instanceof PropertyFetchNode)
			|| $this->layout->getFirst()[0] !== ListLayout::PerLine
		) {
			return null;
		}

		$chain = $link;
		while (
			($chain->parent instanceof MethodCallNode || $chain->parent instanceof PropertyFetchNode)
			&& $chain->parent->object === $chain
		) {
			$chain = $chain->parent;
		}

		$because = $gap->once($chain, fn() => NodeHelpers::findWidthReason($chain, $gap));
		return $because !== null && ($this->firstLinks === self::OwnLine || self::followsCall($link))
			? new Claim(line: Line::Next, because: $because, decision: $this->layout->itemDecision)
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
	 * Whether the link takes a line of its own: some link of its chain begins a line, or with `attached`, the
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
