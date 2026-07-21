<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringPartNode, StringNode};
use function strlen;


/**
 * A backslash that is no escape sequence written as `\\` in double-quoted strings and heredocs,
 * so the reader need not know which sequences PHP interprets; single-quoted strings stay.
 */
#[RuleInfo(
	'dresscode/no-implicit-backslash',
	Stage::Structure,
	description: 'Writes every backslash of a string escaped',
)]
final class NoImplicitBackslashRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [StringNode::class, InterpolatedStringPartNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = match (true) {
			$node instanceof StringNode => $node->quote === '"' ? $node->token : null,
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
			fn($match) => strpbrk($match[1][0], $escapes) === false && !self::guardsInterpolation($token->text, $match[1][1], $node)
				? '\\\\' . $match[1][0]
				: $match[0][0],
			$token->text,
			flags: PREG_OFFSET_CAPTURE,
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
		return $parent instanceof HeredocNode && $parent->isNowdoc()
			? null
			: $node->token;
	}


	/**
	 * Whether the backslash keeps a brace from opening an interpolation, which `{$` does once the backslash is
	 * escaped: the dollar follows in the text, or the brace ends a part of a string that an interpolation follows.
	 */
	private static function guardsInterpolation(string $text, int $offset, Node|Token $node): bool
	{
		return $text[$offset] === '{'
			&& (($text[$offset + 1] ?? null) === '$' || ($offset === strlen($text) - 1 && $node instanceof InterpolatedStringPartNode));
	}
}
