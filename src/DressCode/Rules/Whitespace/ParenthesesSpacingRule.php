<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;


/**
 * No whitespace just inside parentheses on a line: `foo($a)`, not `foo( $a )`.
 */
#[RuleInfo(
	'dresscode/parentheses-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside parentheses',
)]
final class ParenthesesSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		$token = match (true) {
			$node->is('(') => $node,
			$node->is(')') => $node->getPrevious(),
			default => null,
		};
		$space = $token?->getTrailingSpace();
		if (
		    $token !== null
		    && $space !== null
		    && $space !== ''
		    && $context->report($node, 'No whitespace inside parentheses')
		) {
			$token->setTrailingSpace('');
		}
	}
}
