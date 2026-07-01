<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Gap, GapRule, RuleInfo, Stage};
use DressCode\Domains\Count;
use PhpSyntax\Nodes\PlainNodeList;
use PhpSyntax\Nodes\Statement\{EmptyStatementNode, InlineHtmlNode};


/**
 * A statement starts on its own line when another statement of the same list precedes it.
 */
#[RuleInfo(Stage::Formatting)]
final class NoStatementsSharingLineRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('file.statementsPerLine', new Count(1, 1, range: false), '`foo(); bar();` is two lines; `for (;;)` is one statement')];
	}


	public function getClaims(): array
	{
		// the first statement of a list follows no statement, and an empty statement, a close tag among them,
		// stays where it stands
		$break = Claim::nextLine();
		return ['*' => ['statements:item' => [
			fn(Gap $gap) => $gap->index > 0 && !$gap->value instanceof EmptyStatementNode && !self::followsMarkup($gap) ? $break : null,
			null,
		]]];
	}


	/** A statement after markup, or after the hashbang, begins where the open tag put it, not on a line of its own. */
	private static function followsMarkup(Gap $gap): bool
	{
		$list = $gap->value->parent;
		return $list instanceof PlainNodeList && $list->getItems()[($gap->index ?? 0) - 1] instanceof InlineHtmlNode;
	}
}
