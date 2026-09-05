<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * A ternary split over lines takes three: the condition, then `?` with its branch, then `:` with its
 * branch; one split at either operator brings the other along. A ternary kept on one line is left alone,
 * and the `?:` of a short ternary stays together. Where the lines stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-ternary',
	Stage::Formatting,
	description: 'Puts the two operators of a multi-line ternary on lines of their own',
)]
final class MultiLineTernaryRule extends GapRule
{
	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the ternary spans several lines');
		$operator = fn(Gap $gap) => self::isSplit($gap->token->parent) && !($gap->token->is(':') && self::isShort($gap->token->parent))
			? $break
			: null;
		return [TernaryNode::class => ['question' => [$operator, null], 'colon' => [$operator, null]]];
	}


	/** Whether the ternary spreads over lines: an operator of it begins a line. */
	private static function isSplit(?Node $ternary): bool
	{
		return $ternary instanceof TernaryNode && ($ternary->question->startsLine() || $ternary->colon->startsLine());
	}


	private static function isShort(?Node $ternary): bool
	{
		return $ternary instanceof TernaryNode && $ternary->then === null;
	}
}
