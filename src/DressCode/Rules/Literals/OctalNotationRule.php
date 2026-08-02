<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Token;


/**
 * Octal numbers with the explicit `0o` prefix of PHP 8.1: `0o755`, not `0755`.
 */
#[RuleInfo(
	'dresscode/octal-notation',
	Stage::Structure,
	description: 'Writes octal numbers with the 0o prefix',
	minPhpVersion: '8.1',
)]
final class OctalNotationRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [IntegerNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof IntegerNode) {
			return;
		}

		$text = (string) preg_replace('~^0_*([0-7_]+)$~', '0o$1', $node->token->text);
		if (
			$text !== $node->token->text
			&& $context->report($node, "An octal number must be written with the '0o' prefix")
		) {
			$node->token->setText($text);
		}
	}
}
