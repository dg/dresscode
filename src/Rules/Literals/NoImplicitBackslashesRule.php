<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringPartNode, StringNode};
use function strlen;


/**
 * A backslash that is no escape sequence written as `\\` in double-quoted strings and heredocs,
 * so the reader need not know which sequences PHP interprets; single-quoted strings stay.
 */
#[RuleInfo(Stage::Structure)]
final class NoImplicitBackslashesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('literals.backslashes', new Words(['escaped' => 'every backslash written `\\\\`']), 'A backslash of a string that escapes nothing')];
	}


	public function getVisitedNodes(): array
	{
		return [StringNode::class, InterpolatedStringPartNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$token = match (true) {
			$node instanceof StringNode => $node->quote === '"' ? $node->token : null,
			$node instanceof InterpolatedStringPartNode => self::findEscapingToken($node),
			default => null,
		};
		if ($token === null || !str_contains($token->text, '\\')) {
			return;
		}

		// a backtick literal escapes its own delimiter, so `\`` must stay as it is, and a heredoc has no delimiter to escape
		$container = $node instanceof InterpolatedStringPartNode ? $node->parent?->parent : null;
		$escapes = 'nrtvef\$01234567' . match (true) {
			$container instanceof ShellExecNode => '`',
			$container instanceof HeredocNode => '',
			default => '"',
		};
		$escaped = preg_replace_callback(
			'~\\\(.)~s',
			fn($match) => !self::startsEscape($token->text, $match[1][1], $escapes) && !self::guardsInterpolation($token->text, $match[1][1], $node)
				? '\\\\' . $match[1][0]
				: $match[0][0],
			$token->text,
			flags: PREG_OFFSET_CAPTURE,
		);
		if (
			$escaped === $token->text
			|| !$context->report($token, 'A backslash that starts no escape sequence must be escaped as `\\\\`.')
		) {
			return;
		}

		$token->setText($escaped);
	}


	/** The text token of a part of a double-quoted string or a heredoc; null in a nowdoc. */
	private static function findEscapingToken(InterpolatedStringPartNode $node): ?Token
	{
		$parent = $node->parent?->parent;
		return $parent instanceof HeredocNode && $parent->isNowdoc()
			? null
			: $node->token;
	}


	/**
	 * Whether the character after a backslash makes an escape sequence: one of the escapes given, `u` before a brace
	 * and `x` before a hexadecimal digit, which PHP reads as a sequence nowhere else.
	 */
	private static function startsEscape(string $text, int $offset, string $escapes): bool
	{
		return match ($text[$offset]) {
			'u' => ($text[$offset + 1] ?? '') === '{',
			'x' => ctype_xdigit($text[$offset + 1] ?? ''),
			default => str_contains($escapes, $text[$offset]),
		};
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
