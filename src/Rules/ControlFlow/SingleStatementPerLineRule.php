<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Claim;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;
use PhpSyntax\Nodes\Statement\InlineHtmlNode;


/**
 * A statement starts on its own line when another statement of the same list precedes it.
 */
#[RuleInfo(
	'dresscode/single-statement-per-line',
	Stage::Formatting,
	description: 'Puts every statement on its own line',
)]
final class SingleStatementPerLineRule extends GapRule
{
	public function getClaims(): array
	{
		// the first statement of a list follows no statement, and a close tag ending one is the template's
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
		return $list instanceof NodeList && $list->getItems()[($gap->index ?? 0) - 1] instanceof InlineHtmlNode;
	}
}
