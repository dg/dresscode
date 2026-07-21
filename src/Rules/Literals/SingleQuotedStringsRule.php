<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Token;


/**
 * Single quotes for a double-quoted string that gains nothing from them: no interpolation, no escape
 * sequences beyond `\\` and `\"`, no single quote inside.
 */
#[RuleInfo(
	'dresscode/single-quoted-strings',
	Stage::Structure,
	description: 'Uses single quotes where double quotes give nothing',
)]
final class SingleQuotedStringsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [StringNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof StringNode) {
			return;
		}

		$content = substr($node->token->text, 1, -1);
		$rest = str_replace(['\\\\', '\"'], '', $content); // the allowed escapes
		if (
			$node->quote !== '"'
			|| str_contains($content, "'")
			|| str_contains($rest, '\\')
			|| !$context->report($node, 'A plain string must be written with single quotes')
		) {
			return;
		}

		$node->setValue($node->value, "'");
	}
}
