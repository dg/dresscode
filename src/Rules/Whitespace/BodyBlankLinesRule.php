<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\Expression\MatchNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\SwitchNode;


/**
 * No blank line after the opening brace of a block, be it the body of a function, of a control structure,
 * a switch or a match; the class braces belong to dresscode/declaration-blank-lines. The closing brace keeps
 * its blank line unless asked otherwise, because such a line separates the branches of `} catch` and
 * `} else` chains.
 */
#[RuleInfo(
	'dresscode/body-blank-lines',
	Stage::Formatting,
	description: 'Removes the blank line after the opening brace of a block',
)]
final class BodyBlankLinesRule extends GapRule implements ConfigurableRule
{
	private bool $beforeClosingBrace = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'beforeClosingBrace' => Expect::bool(false)->description('The blank line before the closing brace goes too'),
		]);
	}


	public function configure(array $options): void
	{
		$this->beforeClosingBrace = $options['beforeClosingBrace'];
	}


	public function getClaims(): array
	{
		// an empty block has one gap, not two, and it belongs to the brace that closes it
		$braces = [
			'openBrace' => [null, fn(Gap $gap) => ($gap->token->getNext()?->is('}') ?? false) ? null : Claim::blank(0)],
			'closeBrace' => [$this->beforeClosingBrace ? Claim::blank(0) : null, null],
		];
		return [BlockNode::class => $braces, SwitchNode::class => $braces, MatchNode::class => $braces];
	}
}
