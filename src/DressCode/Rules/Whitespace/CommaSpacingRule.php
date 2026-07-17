<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Token;


/**
 * No whitespace before a comma, a single space after it unless the line ends there. Tabs after
 * a comma align columns and stay, unless the option turns the tolerance off.
 */
#[RuleInfo(
	'dresscode/comma-spacing',
	Stage::Formatting,
	description: 'Puts a single space after a comma and none before it',
)]
final class CommaSpacingRule extends Rule implements ConfigurableRule
{
	private bool $tabAlignment = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'tabAlignment' => Expect::bool(true)->description('Whitespace with a tab after a comma stays, as it aligns columns'),
		]);
	}


	public function configure(array $options): void
	{
		$this->tabAlignment = $options['tabAlignment'];
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->is(',') || !$node->parent instanceof SeparatedNodeList) {
			return;
		}

		$previous = $node->getPrevious();
		$before = $previous?->getTrailingSpace();
		if (
			$previous !== null
			&& !$previous->is(',')
			&& $before !== null
			&& $before !== ''
			&& $context->report($node, 'No whitespace before a comma')
		) {
			$previous->setTrailingSpace('');
		}

		$after = $node->getTrailingSpace();
		$next = $node->getNext();
		if (
			$after !== null
			&& $after !== ' '
			&& !($this->tabAlignment && str_contains($after, "\t"))
			&& $next !== null
			&& !$next->is(')', ']')
			&& $context->report($node, 'A single space after a comma')
		) {
			$node->setTrailingSpace(' ');
		}
	}
}
