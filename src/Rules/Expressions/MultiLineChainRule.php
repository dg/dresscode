<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;


/**
 * A chain of method calls and property accesses split over lines has every link on a line of its own, the
 * first one on the line after the expression the chain starts from; with the option, the links before the first
 * one that begins a line stay on the first line and only the links after it take lines of their own. A chain
 * kept on one line is left alone. Where the lines stand is the matter of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-chain',
	Stage::Formatting,
	description: 'Puts every link of a multi-line chain of calls on its own line, the first one included',
)]
final class MultiLineChainRule extends GapRule implements ConfigurableRule
{
	private const OwnLine = 'ownLine';
	private const SameLine = 'sameLine';

	private string $leadingLinks = self::OwnLine;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'leadingLinks' => Expect::anyOf(self::OwnLine, self::SameLine)->default(self::OwnLine)
				->description('The links before the first one that begins a line: ownLine gives each of them a line too, sameLine leaves them on the line the chain starts on'),
		]);
	}


	public function configure(array $options): void
	{
		$this->leadingLinks = $options['leadingLinks'];
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the chain spans several lines');
		$link = ['operator' => [fn(Gap $gap) => $this->isSplit($gap->token->parent) ? $break : null, null]];
		return [MethodCallNode::class => $link, PropertyFetchNode::class => $link];
	}


	/**
	 * Whether the link takes a line of its own: some link of its chain begins a line, or with the option, the
	 * link itself or one before it does.
	 */
	private function isSplit(?Node $link): bool
	{
		if (!$link instanceof MethodCallNode && !$link instanceof PropertyFetchNode) {
			return false;
		}

		// up to the outermost link unless the links before this one decide, then down through the links
		while (
			$this->leadingLinks === self::OwnLine
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
