<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use PhpSyntax\Token;


/**
 * Magic constants in their canonical casing: `__DIR__`, not `__dir__`.
 */
#[RuleInfo(
	'dresscode/magic-constant-casing',
	Stage::Structure,
	description: 'Writes magic constants in uppercase',
)]
final class MagicConstantCasingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [MagicConstantNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof MagicConstantNode) {
			return;
		}

		$canonical = strtoupper($node->token->text);
		if (
			$canonical !== $node->token->text
			&& $context->report($node, "The magic constant must be written '$canonical'")
		) {
			$node->token->setText($canonical);
		}
	}
}
