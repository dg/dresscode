<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine\Gaps;

use DressCode\{Line, Space, Violation};
use PhpSyntax\{Node, Nodes, Token, Trivia};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\{IdentifierNode, NameNode};
use function is_int;


/**
 * The messages of the claims on gaps, in the shapes their families share: `Expected a line break before the closing
 * parenthesis`, `Expected no whitespace before the semicolon`, `Expected 2 blank lines before the method, 1 found`.
 * A token is named by its role or text, a node by its kind (`the method`, `the return`, `the import`).
 * @internal
 */
final class Messages
{
	/**
	 * Names the bracket at the edge of the gap when there is one, otherwise the node the claim was made for; the
	 * gap after the open tag is named by the tag.
	 */
	public static function formatLine(Line $line, string $side, Node|Token $subject, Token $edge): string
	{
		$break = $line === Line::Next ? 'Expected a line break ' : 'Expected no line break ';
		if ($side === 'before' && ($edge->leadingTrivia[0] ?? null)?->id === Trivia::OpenTag) {
			return $break . 'after the opening tag';
		}

		$what = $subject instanceof Node && $edge->is(['{', '}', '(', ')', '[', ']']) ? $edge : $subject;
		return $break . $side . ' ' . self::describe($what);
	}


	public static function formatSpace(Space $space, string $side, Token $at): string
	{
		$requirement = match ($space) {
			Space::None => 'Expected no whitespace',
			Space::Single, Space::SingleOrTabs => 'Expected a single space',
			Space::AtLeastOne, Space::AtLeastOneOrTabs => 'Expected at least one space',
		};
		return "$requirement $side " . self::describe($at);
	}


	/** @param int|array{int, ?int} $count */
	public static function formatBlankLines(int|array $count, string $where, int $found): string
	{
		[$min, $max] = is_int($count) ? [$count, $count] : $count;
		$lines = fn(int $n) => match ($n) {
			0 => 'no blank line',
			1 => '1 blank line',
			default => "$n blank lines",
		};
		$expected = match (true) {
			$min === $max => $lines($min),
			$max === null => 'at least ' . $lines($min),
			$min === 0 => 'at most ' . $lines($max),
			default => "$min to " . $lines($max),
		};
		return "Expected $expected $where, $found found";
	}


	public static function describe(Node|Token $subject): string
	{
		if ($subject instanceof Node) {
			return match (true) {
				$subject instanceof Nodes\Statement\ExpressionStatementNode => match (true) {
					$subject->expression instanceof Nodes\Expression\ThrowNode => 'the throw',
					$subject->expression instanceof Nodes\Expression\YieldNode, $subject->expression instanceof Nodes\Expression\YieldFromNode => 'the yield',
					default => 'the statement',
				},
				$subject instanceof Nodes\Statement\UseNode => 'the import',
				$subject instanceof Nodes\Member\ClassConstNode => 'the constant',
				$subject instanceof Nodes\AttributeGroupNode => 'the attribute',
				$subject instanceof Nodes\ArgumentPlaceholderNode => 'the argument',
				$subject instanceof Nodes\ExpressionNode => 'the expression',
				default => 'the ' . strtolower((string) preg_replace('~(?<!^)[A-Z]~', ' $0', substr($subject::class, strrpos($subject::class, '\\') + 1, -4))),
			};
		}

		$token = $subject;
		return match (true) {
			$token->is('(') => 'the opening parenthesis',
			$token->is(')') => 'the closing parenthesis',
			$token->is('[') => 'the opening bracket',
			$token->is(']') => 'the closing bracket',
			$token->is('{') => 'the opening brace',
			$token->is('}') => 'the closing brace',
			$token->is(',') => 'the comma',
			$token->is(';') => 'the semicolon',
			$token->is(':') => 'the colon',
			$token->is(Token::DoubleColon) => 'the double colon',
			$token->is(Token::DoubleArrow) => 'the double arrow',
			$token->is([Token::ObjectOperator, Token::NullsafeObjectOperator]) => 'the object operator',
			$token->is(Token::Ellipsis) => 'the spread operator',
			$token->is('\\') => 'the namespace separator',
			$token->is(Token::Attribute) => '`#[`',
			$token->is(Token::CloseTag) => 'the close tag',
			$token->is(Token::EndOfFile) => 'the end of the file',
			$token->is('$') => 'the dollar sign',
			$token->parent instanceof VariableNode => 'the variable',
			$token->parent instanceof NameNode, $token->parent instanceof IdentifierNode => 'the name',
			preg_match('~^\(\s*\w+\s*\)$~', $token->text) === 1 => 'the cast',
			preg_match('~^[a-z_]+$~i', $token->text) === 1 => "the `$token->text` keyword",
			$token->is([Token::Integer, Token::Float, Token::ConstantEncapsedString]) => 'the value',
			default => 'the ' . Violation::formatCode($token->text) . ' operator',
		};
	}
}
