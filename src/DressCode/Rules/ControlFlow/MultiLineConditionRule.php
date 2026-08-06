<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\WhileNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * A long condition joined by boolean operators is split so that every part starts a line with its operator,
 * one level deeper than the statement, and the closing parenthesis stands on a line of its own; a parenthesized
 * group with operators inside is split the same way one level deeper. A condition with a comment inside is
 * only reported.
 */
#[RuleInfo(
	'dresscode/multi-line-condition',
	Stage::Formatting,
	description: 'Splits a long condition of if, elseif, while and do-while into one part per line',
)]
final class MultiLineConditionRule extends Rule implements ConfigurableRule
{
	private const BooleanOperators = [
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];

	private int $minLineLength = 121;
	private bool $splitAllParts = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minLineLength' => Expect::int(121)->min(1)->description('A condition on a line at least this long is split'),
			'splitAllParts' => Expect::bool(false)->description('A condition already on several lines is split further until every part has its own line'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minLineLength = $options['minLineLength'];
		$this->splitAllParts = $options['splitAllParts'];
	}


	public function getVisitedTypes(): array
	{
		return [IfNode::class, ElseIfNode::class, WhileNode::class, DoWhileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			&& !$node instanceof ElseIfNode
			&& !$node instanceof WhileNode
			&& !$node instanceof DoWhileNode
		) {
			return;
		}

		$cond = $node->cond;
		$operators = self::countOperators($cond);
		$first = $cond->getFirstToken();
		$last = $cond->getLastToken();
		if ($operators === 0 || $first === null || $last === null) {
			return;
		}

		$style = $context->getStyle();
		$lines = ($last->getLine() ?? 0) - ($first->getLine() ?? 0) + 1;
		$reason = match (true) {
			$lines === 1 => $first->getLineWidth($style) >= $this->minLineLength
				&& ($first->getLine() === $node->openParen->getLine() || self::canLayOut($cond))
				? 'a condition longer than ' . ($this->minLineLength - 1) . ' characters'
				: null,
			$this->splitAllParts && $lines < $operators + 1 => 'parts sharing a line',
			default => null,
		};
		if (
			$reason === null
			|| !$context->report($node->openParen, "Every part of the condition on its own line: $reason")
			|| $node->openParen->hasCommentUpTo($node->closeParen)
		) {
			return;
		}

		$indentation = Indentation::normalize($node->getFirstToken()?->getLineIndentation() ?? '', $style);
		$eol = new Trivia(TriviaKind::EndOfLine, $style->eol);
		$node->openParen->setTrailingTrivia([$eol]);
		self::layOut($cond, $indentation . $style->indent, $style, startsLine: true);
		$last->setTrailingTrivia([$eol]);
		$node->closeParen->setLeadingTrivia($indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)]);
	}


	/**
	 * Puts every operand of a chain of boolean operators on its own line at the indentation, the operator
	 * first; a parenthesized group with operators inside is laid out one level deeper.
	 */
	private static function layOut(ExpressionNode $expr, string $indentation, Style $style, bool $startsLine): void
	{
		$expr->getFirstToken()?->setLeadingTrivia($startsLine ? [new Trivia(TriviaKind::Whitespace, $indentation)] : []);
		$eol = new Trivia(TriviaKind::EndOfLine, $style->eol);
		if ($expr instanceof BinaryNode && $expr->operator->is(...self::BooleanOperators)) {
			self::layOut($expr->left, $indentation, $style, $startsLine);
			$expr->left->getLastToken()?->setTrailingTrivia([$eol]);
			$expr->operator->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation)]);
			$expr->operator->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			self::layOut($expr->right, $indentation, $style, startsLine: false);
		} elseif ($expr instanceof ParenthesizedNode && self::countOperators($expr->expr) > 0) {
			$expr->openParen->setTrailingTrivia([$eol]);
			self::layOut($expr->expr, $indentation . $style->indent, $style, startsLine: true);
			$expr->expr->getLastToken()?->setTrailingTrivia([$eol]);
			$expr->closeParen->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation)]);
		}
	}


	/**
	 * Whether laying the condition out changes anything: a chain of boolean operators, or a parenthesized one;
	 * a single long part already on its own line stays as it is.
	 */
	private static function canLayOut(ExpressionNode $expr): bool
	{
		return ($expr instanceof BinaryNode && $expr->operator->is(...self::BooleanOperators))
			|| ($expr instanceof ParenthesizedNode && self::countOperators($expr->expr) > 0);
	}


	private static function countOperators(ExpressionNode $expr): int
	{
		$count = 0;
		foreach ([$expr, ...$expr->getDescendants(BinaryNode::class)] as $node) {
			if ($node instanceof BinaryNode && $node->operator->is(...self::BooleanOperators)) {
				$count++;
			}
		}

		return $count;
	}
}
