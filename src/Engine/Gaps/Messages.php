<?php declare(strict_types=1);

namespace DressCode\Engine\Gaps;

use DressCode\Line;
use DressCode\Space;
use PhpSyntax\Node;
use PhpSyntax\Nodes;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\TriviaKind;
use function is_int;


/**
 * The messages of the claims on gaps, in the shapes their families share: `A line break before the closing
 * parenthesis`, `No whitespace before the semicolon`, `Expected 2 blank lines before the method, 1 found`.
 * A token is named by its role or text, a node by its kind (`the method`, `the return`, `the import`).
 * @internal
 */
final class Messages
{
	/**
	 * Names the bracket at the edge of the gap when there is one, otherwise the node the claim was made for; the
	 * gap after the open tag is named by the tag.
	 */
	public static function line(Line $line, string $side, Node|Token $subject, Token $edge): string
	{
		$break = $line === Line::Next ? 'A line break ' : 'No line break ';
		if ($side === 'before' && ($edge->leadingTrivia[0] ?? null)?->kind === TriviaKind::OpenTag) {
			return $break . 'after the opening tag';
		}

		$what = $subject instanceof Node && $edge->is('{', '}', '(', ')', '[', ']') ? $edge : $subject;
		return $break . $side . ' ' . self::describe($what);
	}


	public static function space(Space $space, string $side, Token $at): string
	{
		$requirement = match ($space) {
			Space::None => 'No whitespace',
			Space::Single, Space::SingleOrTabs => 'A single space',
			Space::AtLeastSingle, Space::AtLeastSingleOrTabs => 'At least one space',
		};
		return "$requirement $side " . self::describe($at);
	}


	/** @param int|array{int, ?int} $count */
	public static function blankLines(int|array $count, string $where, int $found): string
	{
		[$min, $max] = is_int($count) ? [$count, $count] : $count;
		$lines = fn(int $n) => $n . ' blank line' . ($n === 1 ? '' : 's');
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
			$token->is(TokenKind::DoubleColon) => 'the double colon',
			$token->is(TokenKind::DoubleArrow) => 'the double arrow',
			$token->is(TokenKind::ObjectOperator, TokenKind::NullsafeObjectOperator) => 'the object operator',
			$token->is(TokenKind::Ellipsis) => 'the spread operator',
			$token->is('\\') => 'the namespace separator',
			$token->is(TokenKind::Attribute) => "'#['",
			$token->is(TokenKind::CloseTag) => 'the close tag',
			$token->is(TokenKind::EndOfFile) => 'the end of the file',
			$token->is('$') => 'the dollar sign',
			$token->parent instanceof VariableNode => 'the variable',
			$token->parent instanceof NameNode, $token->parent instanceof IdentifierNode => 'the name',
			preg_match('~^\(\s*\w+\s*\)$~', $token->text) === 1 => 'the cast',
			preg_match('~^[a-z_]+$~i', $token->text) === 1 => "the $token->text keyword",
			$token->is(TokenKind::Integer, TokenKind::Float, TokenKind::ConstantEncapsedString) => 'the value',
			default => "the $token->text operator",
		};
	}
}
