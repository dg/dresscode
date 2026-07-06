<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Node;
use PhpSyntax\Nodes\{ArgumentListNode, VariadicPlaceholderNode};


/**
 * The arguments of a call spread over several lines each take a line of their own, or with `frame` only the
 * first one, the others sharing lines as their author put them; the closing parenthesis stands on a line of
 * its own and each comma on the line of its argument. A call kept on one line is left alone, and where the
 * lines stand is the matter of `IndentationRule`. The parentheses of a first-class callable,
 * `strlen(...)`, stay on one line.
 */
#[RuleInfo(Stage::Formatting)]
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
		]), 'The arguments of a call spread over lines, which is one where an argument or the closing parenthesis begins a line, the closing parenthesis then standing on a line of its own and each comma on the line of its argument')];
	}


	public function configure(Values $values): void
	{
		$this->shape = $values->get('multiline.call')->getWord();
	}


	public function getClaims(): array
	{
		$because = 'the arguments span several lines';
		$break = new Claim(line: Line::Next, because: $because);
		$hug = new Claim(Space::None, line: Line::Same, because: $because);
		$callable = new Claim(line: Line::Same, because: 'a first-class callable has no arguments');
		$decide = fn(Gap $gap, ?Node $list) => match (true) {
			self::isCallable($list) => $callable,
			self::isMultiline($gap, $list) => $break,
			default => null,
		};
		$perLine = $this->shape === self::PerLine;
		return [ArgumentListNode::class => [
			'items:item' => [fn(Gap $gap) => $perLine || $gap->index === 0 ? $decide($gap, $gap->value->parent?->parent) : null, null],
			'items:separator' => [fn(Gap $gap) => self::isMultiline($gap, $gap->token->parent?->parent) ? $hug : null, null],
			'closeParen' => [fn(Gap $gap) => $decide($gap, $gap->token->parent), null],
		]];
	}


	/** Whether the parentheses are those of a first-class callable, `strlen(...)`. */
	private static function isCallable(?Node $list): bool
	{
		return $list instanceof ArgumentListNode && ($list->items->getItems()[0] ?? null) instanceof VariadicPlaceholderNode;
	}


	/** Whether the call spans lines, as the shape it has at its first gap of the pass says. */
	private static function isMultiline(Gap $gap, ?Node $list): bool
	{
		if (!$list instanceof ArgumentListNode || $list->items->isEmpty()) {
			return false;
		}

		return $gap->once($list, fn() => NodeHelpers::isMultiline($list->openParen, $list->items->getItems(), $list->closeParen));
	}
}
