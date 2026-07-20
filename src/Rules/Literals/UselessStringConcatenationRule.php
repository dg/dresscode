<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, CastNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringNode, StringNode};
use function in_array;


/**
 * Two string literals of the same kind concatenated on one line, or with `literals.concatenatedLiteralsOverLines`
 * on any lines, are one literal. Single-quoted ones are joined; double-quoted ones are only reported, because an
 * escape sequence could span the joint. Concatenation with an empty string converts to string and nothing else, so
 * `$x . ''` is `(string) $x`, and inside a longer concatenation the empty literal simply goes away. A comment
 * in the concatenation keeps it as it is.
 */
#[RuleInfo(Stage::Structure)]
final class UselessStringConcatenationRule extends NodeRule
{
	private const OneLine = 'literals.concatenatedLiterals';
	private const OverLines = 'literals.concatenatedLiteralsOverLines';

	private bool $oneLine = true;
	private bool $overLines = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::OneLine, new Words(['joined' => '`\'a\' . \'b\'` is `\'ab\'`, `. \'\'` goes']), 'Two string literals concatenated on one line, which are one literal, and the concatenation with an empty string, which is a cast or nothing'),
			new Decision(self::OverLines, new Words(['joined' => '`\'a\'` and `. \'b\'` on the next line are `\'ab\'`']), 'Two string literals concatenated on different lines'),
		];
	}


	public function configure(Values $values): void
	{
		$this->oneLine = !$values->isKept(self::OneLine);
		$this->overLines = !$values->isKept(self::OverLines);
	}


	public function getVisitedNodes(): array
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
			if ($this->oneLine) {
				$this->dropEmpty($node, self::isEmptyString($left) ? $right : $left, $context);
			}

			return;
		}

		if (
			!$left instanceof StringNode
			|| !$right instanceof StringNode
			|| !$left->token->is(Token::ConstantEncapsedString)
			|| !$right->token->is(Token::ConstantEncapsedString)
		) {
			return;
		}

		$l = $left->token->text;
		$r = $right->token->text;
		$joint = substr($l, -2, 1) . substr($r, 1, 1);
		$single = $l[0] === "'";
		$decision = $left->token->getCurrentLine() === $right->token->getCurrentLine() ? self::OneLine : self::OverLines;
		if (
			$l[0] !== $r[0]
			// '?' . '>' is split on purpose, so that no text around the code reads a tag
			|| $joint === '?>'
			|| $joint === '<?'
			|| !($decision === self::OneLine ? $this->oneLine : $this->overLines)
			|| $node->hasInnerComment()
			|| !$context->report(
				$node->operator,
				'Useless concatenation of two string literals' . ($single ? ', because they make one literal' : ', but an escape sequence could span the joint') . '.',
				decision: $decision,
				fixable: $single,
			)
			|| !$single
		) {
			return;
		}

		$node->replaceWith((new Builder)->expression("'" . substr($l, 1, -1) . substr($r, 1, -1) . "'"));
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
		if (
			$node->hasInnerComment()
			|| !$context->report($node->operator, 'Useless concatenation, because one side is an empty string.', decision: self::OneLine)
		) {
			return;
		}

		$copy = $other->withoutEdgeTrivia();
		$isString = $other instanceof StringNode
			|| $other instanceof InterpolatedStringNode
			|| $other instanceof HeredocNode
			|| ($other instanceof BinaryOpNode && $other->operator->is('.'))
			|| ($other instanceof CastNode && $other->operator->is(Token::StringCast))
			|| ($node->parent instanceof BinaryOpNode && $node->parent->operator->is('.'));
		if ($isString) {
			$node->replaceWith($copy);
			return;
		}

		$node->replaceWith((new Builder)->cast('string', $copy));
	}
}
