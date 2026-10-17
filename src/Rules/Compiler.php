<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\{Analyses, RuleContext};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind};
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, Expression, NameNode, Scalar};
use function count;


/**
 * What the compiler of PHP knows of the code: the calls it optimizes and the values it knows while compiling.
 * @internal
 */
final class Compiler
{
	/**
	 * the functions PHP optimizes a call of => what the call needs: nothing, every argument constant, or arguments of
	 * a shape of their own; compact(), extract(), get_defined_vars() and func_get_arg() are only detected by the optimizer,
	 * not replaced, and assert() is compiled specially whether its name is known to be global or not
	 */
	private const OptimizedFunctions = [
		'array_key_exists' => 'any',
		'boolval' => 'any',
		'call_user_func' => 'any',
		'call_user_func_array' => 'any',
		'count' => 'any',
		'doubleval' => 'any',
		'floatval' => 'any',
		'func_get_args' => 'any',
		'func_num_args' => 'any',
		'get_called_class' => 'any',
		'get_class' => 'any',
		'gettype' => 'any',
		'intval' => 'any',
		'is_array' => 'any',
		'is_bool' => 'any',
		'is_double' => 'any',
		'is_float' => 'any',
		'is_int' => 'any',
		'is_integer' => 'any',
		'is_long' => 'any',
		'is_null' => 'any',
		'is_object' => 'any',
		'is_resource' => 'any',
		'is_scalar' => 'any',
		'is_string' => 'any',
		'sizeof' => 'any',
		'strlen' => 'any',
		'strval' => 'any',
		'chr' => 'constant',
		'constant' => 'constant',
		'define' => 'constant',
		'defined' => 'constant',
		'dirname' => 'constant',
		'extension_loaded' => 'constant',
		'function_exists' => 'constant',
		'ini_get' => 'constant',
		'is_callable' => 'constant',
		'ord' => 'constant',
		'sprintf' => 'format',
		'in_array' => 'lookup',
		'array_slice' => 'arguments',
	];


	/** Whether PHP optimizes a call of the global function, with some arguments or with any. */
	public static function isOptimizedFunction(string $function): bool
	{
		return isset(self::OptimizedFunctions[strtolower($function)]);
	}


	/**
	 * Whether PHP optimizes the call of the global function with its arguments: most of the functions it optimizes with
	 * any, some only with every argument constant, `sprintf()` only with a constant format of `%s` and `%d` alone, `in_array()`
	 * only with a constant array it looks up in a hash and `array_slice()` only of `func_get_args()`; none with a named
	 * argument unless `$named` asks about the call the arguments would make passed positionally in the order they stand,
	 * and none with an unpacked one unless `$unpacked` asks about the call the unpacked last argument would make if it
	 * passed its values one by one, none of them constant.
	 */
	public static function isOptimizedCall(
		Expression\FunctionCallNode $call,
		string $function,
		RuleContext $context,
		bool $unpacked = false,
		bool $named = false,
	): bool
	{
		$values = [];
		$rest = false;
		foreach ($call->arguments->items->getItems() as $argument) {
			if (
				!$argument instanceof ArgumentNode
				|| ($argument->name !== null && !$named)
				|| $rest
				|| ($argument->ellipsis !== null && !$unpacked)
			) {
				return false;
			} elseif ($argument->ellipsis !== null) {
				$rest = true;
			} else {
				$values[] = $argument->value;
			}
		}

		return match (self::OptimizedFunctions[strtolower($function)] ?? null) {
			'any' => true,
			'constant' => $values !== [] && array_all($values, fn(Node $value) => self::isKnownAtCompileTime($value, $context)),
			'format' => self::isConcatenatedFormat($values, $rest),
			'lookup' => self::isLookupArray($values, $context),
			'arguments' => count($values) === 2
				&& $values[0] instanceof Expression\FunctionCallNode
				&& $values[0]->arguments->items->getItems() === []
				&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($values[0], 'func_get_args')
				&& $values[1] instanceof Scalar\IntegerNode,
			default => false,
		};
	}


	/**
	 * Whether `sprintf()` with the arguments is compiled into a concatenation: the format is a string known while compiling
	 * with no placeholder but `%s` and `%d`, one for each value, or for each value and some an unpacked argument passes.
	 * @param  list<Node>  $values
	 */
	private static function isConcatenatedFormat(array $values, bool $rest): bool
	{
		$format = self::findStringValue($values[0] ?? null);
		if ($format === null) {
			return false;
		}

		$format = str_replace('%%', '', $format);
		$placeholders = preg_match_all('~%[sd]~', $format);
		return !preg_match('~%(?![sd])~', $format)
			&& ($rest ? $placeholders >= count($values) - 1 : $placeholders === count($values) - 1);
	}


	/**
	 * Whether `in_array()` with the arguments looks the needle up in a hash built while compiling: the haystack is an array
	 * of strings and integers with constant keys, the strict flag is constant if given, and without it every item is
	 * a string that is not numeric.
	 * @param  list<Node>  $values
	 */
	private static function isLookupArray(array $values, RuleContext $context): bool
	{
		$haystack = $values[1] ?? null;
		$flag = $values[2] ?? null;
		if (
			!$haystack instanceof Expression\ArrayNode
			|| count($values) > 3
			|| ($flag !== null && !self::isKnownAtCompileTime($flag, $context))
		) {
			return false;
		}

		$strict = $flag instanceof Scalar\BooleanNode && $flag->toValue();
		foreach ($haystack->items->getItems() as $item) {
			if (
				!$item instanceof ArrayItemNode
				|| $item->ellipsis !== null
				|| $item->ampersand !== null
				|| ($item->key !== null && !self::isKnownAtCompileTime($item->key, $context))
			) {
				return false;
			}

			$value = $item->value;
			$string = self::findStringValue($value);
			$integer = $value instanceof Scalar\IntegerNode
				|| ($value instanceof Expression\UnaryOpNode
					&& $value->operator->is(['-', '+'])
					&& $value->expression instanceof Scalar\IntegerNode);
			if ($string !== null ? !$strict && is_numeric($string) : !$strict || !$integer) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Whether the value of the expression is known while compiling: a literal, an operation on known values, or a constant
	 * of PHP the compiler reads, which is one written qualified, imported or in the global namespace, and with `$unqualified`
	 * also one written bare in a namespace, which PHP reaches by the fallback at run time.
	 */
	public static function isKnownAtCompileTime(?Node $node, RuleContext $context, bool $unqualified = false): bool
	{
		return match (true) {
			$node instanceof Scalar\StringNode,
			$node instanceof Scalar\IntegerNode,
			$node instanceof Scalar\FloatNode,
			$node instanceof Scalar\BooleanNode,
			$node instanceof Scalar\NullNode,
			$node instanceof Scalar\MagicConstantNode => true,
			$node instanceof Scalar\HeredocNode => !$node->hasInterpolation(),
			$node instanceof Expression\ConstantFetchNode => self::isReadConstant($node, $context, $unqualified),
			$node instanceof Expression\ParenthesizedNode, $node instanceof Expression\UnaryOpNode, $node instanceof Expression\CastNode
				=> self::isKnownAtCompileTime($node->expression, $context, $unqualified),
			$node instanceof Expression\BinaryOpNode => self::isKnownAtCompileTime($node->left, $context, $unqualified)
				&& self::isKnownAtCompileTime($node->right, $context, $unqualified),
			default => false,
		};
	}


	/** Whether the fetch reads a constant of PHP while compiling, or with `$unqualified` at least reaches one by the fallback. */
	private static function isReadConstant(
		Expression\ConstantFetchNode $fetch,
		RuleContext $context,
		bool $unqualified,
	): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$name = $fetch->name;
		return $context->getAnalysis(Analyses\PhpSymbols::class)->isBuiltinConstant($resolver->resolveConstant($name))
			&& ($unqualified || self::isNameKnown($name, SymbolKind::Constant, $context));
	}


	/**
	 * Whether the compiler knows what the name of a function or a constant stands for, with no fallback left to the
	 * run: it is qualified, the file declares no namespace, or the file imports it.
	 */
	public static function isNameKnown(NameNode $name, SymbolKind $kind, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$key = $kind === SymbolKind::Function ? strtolower($name->text) : $name->text;
		return $name->form !== NameForm::Unqualified
			|| $resolver->getNamespace($name) === ''
			|| isset($resolver->getImports($kind, $name)[$key]);
	}


	/** The value of a string known while compiling, a literal or a concatenation of literals; null for any other expression. */
	private static function findStringValue(?Node $node): ?string
	{
		if ($node instanceof Expression\BinaryOpNode && $node->operator->is('.')) {
			$left = self::findStringValue($node->left);
			$right = self::findStringValue($node->right);
			return $left === null || $right === null ? null : $left . $right;
		}

		return match (true) {
			$node instanceof Scalar\StringNode => $node->toValue(),
			$node instanceof Scalar\HeredocNode && !$node->hasInterpolation() => $node->toValue(),
			$node instanceof Expression\ParenthesizedNode => self::findStringValue($node->expression),
			default => null,
		};
	}
}
