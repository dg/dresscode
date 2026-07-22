<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\InlineHtmlNode;
use PhpSyntax\Token;


/**
 * No whitespace before a line ending inside a multi-line string or inline HTML; this changes
 * the value of the string.
 */
#[RuleInfo(
	'dresscode/no-trailing-whitespace-in-string',
	Stage::Structure,
	description: 'Removes trailing whitespace from string lines',
)]
final class NoTrailingWhitespaceInStringRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [StringNode::class, InterpolatedStringPartNode::class, InlineHtmlNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = match (true) {
			$node instanceof StringNode => $node->token,
			$node instanceof InterpolatedStringPartNode => $node->text,
			$node instanceof InlineHtmlNode => $node->html,
			default => null,
		};
		if ($token === null) {
			return;
		}

		$stripped = preg_replace('~[ \t]+(?=\R)~', '', $token->text);
		if (
			$stripped === $token->text
			|| !$context->report($token, 'Trailing whitespace in a string')
		) {
			return;
		}

		$token->setText($stripped);
	}
}
