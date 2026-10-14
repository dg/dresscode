<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\MatchNode;


/**
 * The arms of a `match` whose braces stand on different lines stand one on a line. Where the braces stand is the
 * matter of `BracesPositionRule`, where the lines stand the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class MultilineMatchRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'multiline.match',
			new Words(['perLine' => 'every arm on a line of its own']),
			'The arms of a `match` whose braces stand on different lines',
			['Not about the conditions of one arm, which `multiline.matchArmConditions` spreads'],
		)];
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the match spans several lines');
		return [MatchNode::class => [
			'arms:item' => [fn(Gap $gap) => $gap->index && self::spansLines($gap->value->parent?->parent, $gap) ? $break : null, null],
		]];
	}


	/** Whether the braces of the match stand on different lines, as the first of its gaps found them. */
	private static function spansLines(?Node $match, Gap $gap): bool
	{
		return $match instanceof MatchNode
			&& $gap->once($match, fn() => $match->openBrace->getCurrentLine() !== $match->closeBrace->getCurrentLine());
	}
}
