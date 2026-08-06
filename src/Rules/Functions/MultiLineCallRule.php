<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Claim;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;


/**
 * The arguments of a call spread over several lines each take a line of their own, with the closing
 * parenthesis on a line of its own and each comma on the line of its argument; a call kept on one line
 * is left alone, and where the lines stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-call',
	Stage::Formatting,
	description: 'Puts every argument of a multi-line call on its own line',
)]
final class MultiLineCallRule extends GapRule
{
	public function getClaims(): array
	{
		$because = 'the arguments span several lines';
		$break = new Claim(line: Line::Next, because: $because);
		$hug = new Claim(Space::None, line: Line::Same, because: $because);
		return [ArgumentListNode::class => [
			'items:item' => [fn(Gap $gap) => self::isBroken($gap, $gap->value->parent?->parent) ? $break : null, null],
			'items:separator' => [fn(Gap $gap) => self::isBroken($gap, $gap->token->parent?->parent) ? $hug : null, null],
			'closeParen' => [fn(Gap $gap) => self::isBroken($gap, $gap->token->parent) ? $break : null, null],
		]];
	}


	/**
	 * Whether the call spreads over lines: a line break after the opening parenthesis, an argument starting
	 * a line, or the closing parenthesis doing so. A comment after the parenthesis ends its line without
	 * breaking the list.
	 */
	private static function isBroken(Gap $gap, ?Node $list): bool
	{
		if (!$list instanceof ArgumentListNode || $list->items->isEmpty()) {
			return false;
		}

		return $gap->once($list, fn() => self::isBrokenNow($list));
	}


	private static function isBrokenNow(ArgumentListNode $list): bool
	{
		$open = $list->openParen;
		if (($open->getTrailingSpace() === null && !$open->hasComment()) || $list->closeParen->startsLine()) {
			return true;
		}

		foreach ($list->items->getItems() as $arg) {
			if ($arg->getFirstToken()?->startsLine()) {
				return true;
			}
		}

		return false;
	}
}
