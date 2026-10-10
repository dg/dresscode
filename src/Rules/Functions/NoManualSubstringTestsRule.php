<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode};
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
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'], analyses: [NameResolver::class])]
final class NoManualSubstringTestsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.functions.substringFunctions', Domain::adopted(), '`str_contains()`, `str_starts_with()`, `str_ends_with()` for `strpos() !== false`, `substr() ===`')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\BinaryOpNode
			|| !$node->operator->is([Token::IsIdentical, Token::IsNotIdentical])
			|| (!$node->left instanceof Expression\FunctionCallNode && !$node->right instanceof Expression\FunctionCallNode)
		) {
			return;
		}

		$identical = $node->operator->is(Token::IsIdentical);
		$test = self::readTest($node->left, $node->right, $identical, $context)
			?? self::readTest($node->right, $node->left, $identical, $context);
		if ($test === null || $node->hasInnerComment()) {
			return;
		}

		$call = $test['call'];
		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$test['haystack'], $test['needle']], $context);
		$message = "The `{$test['source']}()` comparison must be written with `{$test['function']}()`.";
		if (!$context->report(
			$node,
			$message,
			risk: $test['risky'] ? Risk::BehaviorChanges : ($uncertainty === null ? null : Risk::NameUncertain),
			because: $test['risky']
				? (str_starts_with($test['source'], 'mb_') ? 'the `mb_` function counts characters where the new one counts bytes' : '`str_ends_with()` finds an empty needle in any haystack')
				: $uncertainty,
		)) {
			return;
		}

		$spelling = CodeWriter::spellFunction($test['function'], $call->name, $context);
		$rewritten = (new Builder)->call($spelling, [$test['haystack'], $test['needle']]);
		$node->replaceWith($test['positive'] ? $rewritten : NodeHelpers::negate($rewritten));
	}


	/**
	 * The test the call and the expression it is compared with make together, null where they make none.
	 * @return ?array{call: Expression\FunctionCallNode, source: string, function: string, haystack: ExpressionNode, needle: ExpressionNode, positive: bool, risky: bool}
	 */
	private static function readTest(
		ExpressionNode $call,
		ExpressionNode $compared,
		bool $identical,
		RuleContext $context,
	): ?array
	{
		$sources = ['strpos' => true, 'mb_strpos' => true, 'strstr' => true, 'mb_strstr' => true, 'substr' => true, 'strncmp' => true];
		$source = $call instanceof Expression\FunctionCallNode ? GlobalCalls::findFunction($call, $sources, $context) : null;
		$arguments = $source === null ? null : $call->arguments->getPlainValues();
		if ($arguments === null) {
			return null;
		}

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
			'substr' => self::readSubstrTest($arguments, $compared, $identical, $context),
			'strncmp' => count($arguments) === 3
				&& self::isZero($compared)
				&& (
					self::repeats(self::readStrlen($arguments[2], $context), $arguments[1])
					|| (($length = self::readLiteralLength($arguments[1])) !== null && self::isInteger($arguments[2], $length))
				)
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
	 * The test a comparison of `substr()` with the needle makes: the length the call cuts is the length of the
	 * needle, either measured by `strlen()` of the needle itself or written as the length of a literal.
	 * @param  list<ExpressionNode>  $arguments
	 * @return ?array{string, ExpressionNode, ExpressionNode, bool, bool}
	 */
	private static function readSubstrTest(
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


	/** The argument of a call of `strlen()`, null for any other expression. */
	private static function readStrlen(ExpressionNode $expression, RuleContext $context): ?ExpressionNode
	{
		$arguments = $expression instanceof Expression\FunctionCallNode && GlobalCalls::findFunction($expression, ['strlen' => true], $context) !== null
			? $expression->arguments->getPlainValues()
			: null;
		return $arguments !== null && count($arguments) === 1 ? $arguments[0] : null;
	}


	/** Whether the two expressions are the same needle written twice, which the test may therefore read once. */
	private static function repeats(?ExpressionNode $measured, ExpressionNode $compared): bool
	{
		return $measured !== null && $measured->matches($compared) && $compared->isRepeatableRead();
	}


	private static function isFalse(ExpressionNode $expression): bool
	{
		return $expression instanceof BooleanNode && !$expression->toValue();
	}


	private static function isZero(ExpressionNode $expression): bool
	{
		return self::isInteger($expression, 0);
	}


	private static function isInteger(ExpressionNode $expression, int $value): bool
	{
		return $expression instanceof IntegerNode && $expression->toValue() === $value;
	}


	/** The length in bytes of a non-empty string literal, null for any other expression and for an empty one. */
	private static function readLiteralLength(ExpressionNode $expression): ?int
	{
		return $expression instanceof StringNode && $expression->toValue() !== ''
			? strlen($expression->toValue())
			: null;
	}
}
