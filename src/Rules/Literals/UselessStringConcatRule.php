<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token, TokenKind};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, CastNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringNode, StringNode};
use function in_array;


/**
 * Two string literals of the same kind concatenated on one line, or on any lines without allowMultiline, are
 * one literal. Single-quoted ones are joined; double-quoted ones are only reported, because an escape
 * sequence could span the joint. Concatenation with an empty string converts to string and nothing else, so
 * `$x . ''` is `(string) $x`, and inside a longer concatenation the empty literal simply goes away. A comment
 * in the concatenation keeps it as it is.
 */
#[RuleInfo(
	'dresscode/useless-string-concat',
	Stage::Structure,
	description: 'Joins two string literals concatenated on one line and drops concatenation with an empty string',
	group: Group::Cleanup,
)]
final class UselessStringConcatRule extends NodeRule implements ConfigurableRule
{
	private bool $allowMultiline = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'allowMultiline' => Expect::bool(true)->description('Literals on different lines may stay concatenated'),
		]);
	}


	public function configure(array $options): void
	{
		$this->allowMultiline = $options['allowMultiline'];
	}


	public function getVisitedTypes(): array
	{
		return [BinaryOpNode::class];
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode || !$node->operator->is('.')) {
			return;
		}

		$left = $node->left;
		$right = $node->right;
		if (self::isEmptyString($left) || self::isEmptyString($right)) {
			$this->dropEmpty($node, self::isEmptyString($left) ? $right : $left, $context);
			return;
		}

		if (
			!$left instanceof StringNode
			|| !$right instanceof StringNode
			|| $left->token->kind !== TokenKind::ConstantEncapsedString
			|| $right->token->kind !== TokenKind::ConstantEncapsedString
		) {
			return;
		}

		$l = $left->token->text;
		$r = $right->token->text;
		$joint = substr($l, -2, 1) . substr($r, 1, 1);
		$single = $l[0] === "'";
		if (
			$l[0] !== $r[0]
			// '?' . '>' is split on purpose, so that no text around the code reads a tag
			|| $joint === '?>'
			|| $joint === '<?'
			|| ($this->allowMultiline && $left->token->getLine() !== $right->token->getLine())
			|| $node->hasComment()
			|| !$context->report(
				$node->operator,
				'Useless concatenation of two string literals' . ($single ? '' : ', but an escape sequence could span the joint'),
				fixable: $single,
			)
			|| !$single
		) {
			return;
		}

		$node->replaceWith((new Parser)->parseExpression("'" . substr($l, 1, -1) . substr($r, 1, -1) . "'"));
	}


	private static function isEmptyString(ExpressionNode $expr): bool
	{
		return $expr instanceof StringNode && in_array($expr->token->text, ["''", '""'], true);
	}


	/**
	 * The other operand alone when it is a string already or part of a longer concatenation, else cast
	 * to string, in parentheses when it is not a primary expression.
	 */
	private function dropEmpty(BinaryOpNode $node, ExpressionNode $other, RuleContext $context): void
	{
		if ($node->hasComment() || !$context->report($node->operator, 'Useless concatenation with an empty string')) {
			return;
		}

		$copy = $other->withoutEdgeTrivia();
		$isString = $other instanceof StringNode
			|| $other instanceof InterpolatedStringNode
			|| $other instanceof HeredocNode
			|| ($other instanceof BinaryOpNode && $other->operator->is('.'))
			|| ($other instanceof CastNode && $other->cast->kind === TokenKind::StringCast)
			|| ($node->parent instanceof BinaryOpNode && $node->parent->operator->is('.'));
		if ($isString) {
			$node->replaceWith($copy);
			return;
		}

		$cast = (new Parser)->parseExpression('(string) 0');
		assert($cast instanceof CastNode);
		$cast->expression->replaceWithExpression($copy);
		$node->replaceWith($cast);
	}
}
