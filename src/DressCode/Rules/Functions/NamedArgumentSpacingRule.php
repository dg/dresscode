<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Token;


/**
 * A named argument as `name: $value`: no whitespace before the colon, a single space after it.
 */
#[RuleInfo(
	'dresscode/named-argument-spacing',
	Stage::Formatting,
	description: 'Normalizes whitespace around the colon of a named argument',
)]
final class NamedArgumentSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArgumentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ArgumentNode || $node->name === null || $node->colon === null) {
			return;
		}

		foreach ([[$node->name->token, ''], [$node->colon, ' ']] as [$token, $expected]) {
			$space = $token->getTrailingSpace();
			if (
			    $space !== null
			    && $space !== $expected
			    && $context->report($node->colon, "A named argument must be written as 'name: value'")
			) {
				$token->setTrailingSpace($expected);
			}
		}
	}
}
