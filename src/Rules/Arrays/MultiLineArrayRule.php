<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * An array spread over lines has every item on a line of its own (or several items on one, as the option
 * allows), each comma on the line of its item and the closing bracket on a line of its own, while the opening
 * bracket stays on the line of the code before it (an assignment, a return, a double arrow); an array kept on
 * one line is left alone. Where the lines stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-array',
	Stage::Formatting,
	description: 'Puts every item of a multi-line array on its own line and the opening bracket on the line before',
)]
final class MultiLineArrayRule extends GapRule implements ConfigurableRule
{
	private bool $oneItemPerLine = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'oneItemPerLine' => Expect::bool(true)->description('Every item on a line of its own; false lets items share a line'),
		]);
	}


	public function configure(array $options): void
	{
		$this->oneItemPerLine = $options['oneItemPerLine'];
	}


	public function getClaims(): array
	{
		$because = 'the array spans several lines';
		$break = new Claim(line: Line::Next, because: $because);
		$hug = new Claim(Space::None, line: Line::Same, because: $because);
		$follow = new Claim(line: Line::Same, because: $because);
		return [ArrayNode::class => [
			'items:item' => [$this->oneItemPerLine ? fn(Gap $gap) => self::isBroken($gap, $gap->value->parent?->parent) ? $break : null : null, null],
			'items:separator' => [fn(Gap $gap) => self::isBroken($gap, $gap->token->parent?->parent) ? $hug : null, null],
			'closeDelimiter' => [fn(Gap $gap) => self::isBroken($gap, $gap->token->parent) ? $break : null, null],
			'openDelimiter' => [fn(Gap $gap) => self::isBroken($gap, $gap->token->parent) && self::follows($gap->token) ? $follow : null, null],
		]];
	}


	/**
	 * Whether the array spreads over lines: a line break after the opening bracket, an item starting a line,
	 * or the closing bracket doing so. A comment after the bracket ends its line without breaking the array.
	 */
	private static function isBroken(Gap $gap, ?Node $array): bool
	{
		if (!$array instanceof ArrayNode || $array->items->isEmpty()) {
			return false;
		}

		return $gap->once($array, fn() => self::isBrokenNow($array));
	}


	private static function isBrokenNow(ArrayNode $array): bool
	{
		$open = $array->openDelimiter;
		if (($open->getTrailingSpace() === null && !$open->hasComment()) || $array->closeDelimiter->startsLine()) {
			return true;
		}

		foreach ($array->items->getItems() as $item) {
			if ($item->getFirstToken()?->startsLine()) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Whether the bracket follows code it belongs on the line of: what hands the array over (an assignment,
	 * a double arrow, a return, a yield); an array that is an item of a list is placed by the rule of the list.
	 */
	private static function follows(Token $bracket): bool
	{
		$previous = $bracket->getPrevious();
		return $previous !== null
			&& ($previous->is(TokenKind::DoubleArrow, TokenKind::Return, TokenKind::Yield, TokenKind::Echo)
				|| (str_ends_with($previous->text, '=') && !$previous->is('==', '===', '!=', '!==', '<=', '>=', '<>')));
	}
}
