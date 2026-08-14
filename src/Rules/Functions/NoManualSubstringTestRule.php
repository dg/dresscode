<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, Expression, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Scalar\{BooleanNode, IntegerNode, StringNode};
use function count, strlen;


/**
 * A test whether one string holds another is written with the function PHP 8.0 gave it: `str_contains()`,
 * `str_starts_with()` or `str_ends_with()` instead of a comparison of `strpos()` with false or zero, of
 * `strstr()` with false, of `substr()` with the needle, or of `strncmp()` with zero. The needle a source form
 * spells twice must be the same expression and free of side effects, because the test reads it once. Only
 * the strict comparisons are read: `strpos($h, $n) == false` is true at position zero as well, so it is
 * a different question. `stripos()` stays, PHP has no case-insensitive counterpart of these functions.
 *
 * The length `substr()` cuts says which part is compared, either as `strlen()` of the needle or as the
 * length of a literal, so `substr($h, 0, 4) === '.php'` is read as well. `substr($h, -strlen($n)) === $n`
 * is risky unless the needle is a literal: for an empty needle the comparison asks whether the haystack
 * itself is empty, while `str_ends_with($h, '')` is always true. The `mb_` functions are risky throughout,
 * because they count characters where the new functions count bytes, and the two agree only in an encoding
 * none of whose trailing bytes can begin a character; which encoding is in force the code does not say.
 */
#[RuleInfo(
	'dresscode/no-manual-substring-test',
	Stage::Structure,
	description: 'Replaces a hand-written test for a substring with str_contains(), str_starts_with() or str_ends_with()',
	group: Group::Modernization,
	requires: ['php' => '>=8.0'],
)]
final class NoManualSubstringTestRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\BinaryOpNode
			|| !$node->operator->is(TokenKind::IsIdentical, TokenKind::IsNotIdentical)
			|| $node->hasComment()
		) {
			return;
		}

		$identical = $node->operator->is(TokenKind::IsIdentical);
		$test = $this->describe($node->left, $node->right, $identical, $context)
			?? $this->describe($node->right, $node->left, $identical, $context);
		if ($test === null) {
			return;
		}

		$call = $test['call'];
		assert($call->name instanceof NameNode);
		$uncertainty = NodeHelpers::findUncertainty($call, $context);
		$message = "The {$test['source']}() comparison must be written with {$test['function']}()";
		if (!$context->report($node, $message . $uncertainty, risky: $test['risky'] || $uncertainty !== null)) {
			return;
		}

		$spelling = NodeHelpers::spellGlobalFunction($test['function'], $call->name, $context);
		$arguments = ArgumentListNode::of($test['haystack']->withoutEdgeTrivia(), $test['needle']->withoutEdgeTrivia());
		$rewritten = Expression\FunctionCallNode::of(NameNode::fromText($spelling), $arguments);
		$node->replaceWith($test['positive'] ? $rewritten : NodeHelpers::negate($rewritten));
	}


	/**
	 * The test the call and the expression it is compared with make together, null where they make none.
	 * @return ?array{call: Expression\FunctionCallNode, source: string, function: string, haystack: ExpressionNode, needle: ExpressionNode, positive: bool, risky: bool}
	 */
	private function describe(
		ExpressionNode $call,
		ExpressionNode $compared,
		bool $identical,
		RuleContext $context,
	): ?array
	{
		$arguments = self::readArguments($call, $context);
		if ($arguments === null) {
			return null;
		}

		assert($call instanceof Expression\FunctionCallNode && $call->name instanceof NameNode);
		$source = strtolower($context->getAnalysis(NameResolver::class)->resolveFunction($call->name));
		$multibyte = str_starts_with($source, 'mb_');
		$shape = match ($source) {
			'strpos', 'mb_strpos' => count($arguments) === 2 ? match (true) {
				self::isFalse($compared) => ['str_contains', $arguments[0], $arguments[1], !$identical, $multibyte],
				self::isZero($compared) => ['str_starts_with', $arguments[0], $arguments[1], $identical, $multibyte],
				default => null,
			} : null,
			'strstr', 'mb_strstr' => count($arguments) === 2 && self::isFalse($compared)
				? ['str_contains', $arguments[0], $arguments[1], !$identical, $multibyte]
				: null,
			'substr' => self::describeSubstr($arguments, $compared, $identical, $context),
			'strncmp' => count($arguments) === 3
				&& self::isZero($compared)
				&& self::repeats(self::readStrlen($arguments[2], $context), $arguments[1])
				? ['str_starts_with', $arguments[0], $arguments[1], $identical, false]
				: null,
			default => null,
		};

		return $shape === null
			? null
			: ['call' => $call, 'source' => $source, 'function' => $shape[0], 'haystack' => $shape[1],
				'needle' => $shape[2], 'positive' => $shape[3], 'risky' => $shape[4]];
	}


	/**
	 * The test a comparison of substr() with the needle makes: the length the call cuts is the length of the
	 * needle, either measured by strlen() of the needle itself or written as the length of a literal.
	 * @param  list<ExpressionNode>  $arguments
	 * @return ?array{string, ExpressionNode, ExpressionNode, bool, bool}
	 */
	private static function describeSubstr(
		array $arguments,
		ExpressionNode $compared,
		bool $identical,
		RuleContext $context,
	): ?array
	{
		$length = self::readLiteralLength($compared);
		if (count($arguments) === 3 && self::isZero($arguments[1])) {
			return self::repeats(self::readStrlen($arguments[2], $context), $compared)
				|| ($length !== null && self::isInteger($arguments[2], $length))
				? ['str_starts_with', $arguments[0], $compared, $identical, false]
				: null;

		} elseif (
			count($arguments) === 2
			&& $arguments[1] instanceof Expression\UnaryOpNode
			&& $arguments[1]->operator->is('-')
		) {
			$measured = $arguments[1]->expression;
			return match (true) {
				$length !== null && self::isInteger($measured, $length) => ['str_ends_with', $arguments[0], $compared, $identical, false],
				self::repeats(self::readStrlen($measured, $context), $compared) => ['str_ends_with', $arguments[0], $compared, $identical, $length === null],
				default => null,
			};
		}

		return null;
	}


	/**
	 * The values of the arguments of a call of a global function, null where the call is of another kind or
	 * names an argument, passes one by reference or unpacks one, which is no plain list of values.
	 * @return ?list<ExpressionNode>
	 */
	private static function readArguments(ExpressionNode $call, RuleContext $context): ?array
	{
		if (
			!$call instanceof Expression\FunctionCallNode
			|| !$call->name instanceof NameNode
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call)
		) {
			return null;
		}

		$values = [];
		foreach ($call->arguments->items as $argument) {
			if (!$argument instanceof ArgumentNode || $argument->name || $argument->ampersand || $argument->ellipsis) {
				return null;
			}
			$values[] = $argument->value;
		}

		return $values;
	}


	/** The argument of a call of strlen(), null for any other expression. */
	private static function readStrlen(ExpressionNode $expression, RuleContext $context): ?ExpressionNode
	{
		$arguments = self::readArguments($expression, $context);
		assert($expression instanceof Expression\FunctionCallNode || $arguments === null);
		return $arguments !== null
			&& count($arguments) === 1
			&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($expression, 'strlen')
			? $arguments[0]
			: null;
	}


	/** Whether the two expressions are the same needle written twice, which the test may therefore read once. */
	private static function repeats(?ExpressionNode $measured, ExpressionNode $compared): bool
	{
		return $measured !== null && $measured->matches($compared) && $compared->isRepeatableRead();
	}


	private static function isFalse(ExpressionNode $expression): bool
	{
		return $expression instanceof BooleanNode && !$expression->value;
	}


	private static function isZero(ExpressionNode $expression): bool
	{
		return self::isInteger($expression, 0);
	}


	private static function isInteger(ExpressionNode $expression, int $value): bool
	{
		return $expression instanceof IntegerNode && $expression->value === $value;
	}


	/** The length in bytes of a non-empty string literal, null for any other expression and for an empty one. */
	private static function readLiteralLength(ExpressionNode $expression): ?int
	{
		return $expression instanceof StringNode && $expression->value !== ''
			? strlen($expression->value)
			: null;
	}
}
