<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\{MethodCallNode, PropertyFetchNode};


/**
 * A chain of method calls and property accesses split over lines has every link on a line of its own, the
 * first one on the line after the expression the chain starts from; with `sameLine`, the links before the first
 * one that begins a line stay on the first line and only the links after it take lines of their own. A chain
 * kept on one line is left alone. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class MultilineChainRule extends GapRule
{
	private const OwnLine = 'ownLine';
	private const SameLine = 'sameLine';
	private const FirstLinks = 'multiline.shape.chainFirstLinks';

	private string $firstLinks = self::OwnLine;


	public static function getDecisions(): array
	{
		return [
			new Decision('multiline.shape.chain', new Words(['perLine' => 'every link on a line of its own']), 'The links of a chain of method calls and property accesses spread over lines'),
			new Decision(self::FirstLinks, new Words([
				self::OwnLine => 'the first link begins a line too',
				self::SameLine => 'the links before the first one beginning a line stay on the line the chain starts on',
			]), 'Where the links stand that come before the first one beginning a line', parameter: true, default: self::OwnLine),
		];
	}


	public function configure(Values $values): void
	{
		$this->firstLinks = $values->get(self::FirstLinks)->getWord();
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the chain spans several lines');
		$link = ['operator' => [fn(Gap $gap) => $this->isMultiline($gap->token->parent) ? $break : null, null]];
		return [MethodCallNode::class => $link, PropertyFetchNode::class => $link];
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
