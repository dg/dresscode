<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Token;


/**
 * A single space around `?` and `:` of a ternary, `?:` written together, unless a line break intervenes.
 */
#[RuleInfo(
	'dresscode/ternary-operator-spacing',
	Stage::Formatting,
	description: 'Puts a single space around the ternary operators',
)]
final class TernaryOperatorSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TernaryNode) {
			return;
		}

		$short = $node->if === null;
		$expected = [
			[$node->question->getPrevious(), ' '],
			[$node->question, $short ? '' : ' '],
			[$node->colon->getPrevious(), $short ? null : ' '],
			[$node->colon, ' '],
		];
		foreach ($expected as [$token, $space]) {
			$current = $token?->getTrailingSpace();
			if ($token === null || $space === null || $current === null || $current === $space) {
				continue;
			}

			if ($context->report($node->question, 'Wrong whitespace around the ternary operator')) {
				$token->setTrailingSpace($space);
			}
		}
	}
}
