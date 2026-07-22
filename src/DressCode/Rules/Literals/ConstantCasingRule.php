<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Token;


/**
 * Lowercase `true`, `false` and `null`; a qualified name like `\TRUE` is somebody's own constant and stays.
 */
#[RuleInfo(
	'dresscode/constant-casing',
	Stage::Structure,
	description: 'Lowercases true, false and null',
)]
final class ConstantCasingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ConstantFetchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ConstantFetchNode) {
			return;
		}

		$token = $node->name->token;
		$lower = strtolower($token->text);
		if (
			!in_array($lower, ['true', 'false', 'null'], strict: true)
			|| $token->text === $lower
			|| !$context->report($node, "The constant '{$token->text}' must be written '$lower'")
		) {
			return;
		}

		$token->setText($lower);
	}
}
