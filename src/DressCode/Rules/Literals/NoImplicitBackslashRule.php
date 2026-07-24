<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\HeredocNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Token;


/**
 * A backslash that is no escape sequence written as `\\` in double-quoted strings and heredocs,
 * so the reader need not know which sequences PHP interprets; single-quoted strings stay.
 */
#[RuleInfo(
	'dresscode/no-implicit-backslash',
	Stage::Structure,
	description: 'Writes every backslash of a string escaped',
)]
final class NoImplicitBackslashRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [StringNode::class, InterpolatedStringPartNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = match (true) {
			$node instanceof StringNode => $node->token->text[0] === '"' ? $node->token : null,
			$node instanceof InterpolatedStringPartNode => $this->findEscapingToken($node),
			default => null,
		};
		if ($token === null) {
			return;
		}

		// a backtick literal escapes its own delimiter, so `\`` must stay as it is
		$escapes = 'nrtvef\$"01234567xu' . ($node instanceof InterpolatedStringPartNode && $node->parent?->parent instanceof ShellExecNode ? '`' : '');
		$escaped = preg_replace_callback(
			'~\\\(.)~s',
			fn($match) => strpbrk($match[1], $escapes) === false
				? '\\\\' . $match[1]
				: $match[0],
			$token->text,
		);
		if (
			$escaped === $token->text
			|| !$context->report($token, 'A backslash that is not an escape sequence must be escaped')
		) {
			return;
		}

		$token->setText($escaped);
	}


	/** The text token of a part of a double-quoted string or a heredoc; null in a nowdoc. */
	private function findEscapingToken(InterpolatedStringPartNode $node): ?Token
	{
		$parent = $node->parent?->parent;
		return $parent instanceof HeredocNode && str_contains($parent->openDelimiter->text, "'")
			? null
			: $node->text;
	}
}
