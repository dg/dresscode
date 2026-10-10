<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\RuleContext;
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Nodes\Expression\{ConstantFetchNode, FunctionCallNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\{IntegerNode, NullNode, StringNode};
use function is_int, strlen;


/**
 * An argument whose value PHP deprecated, written out where the code shows it: `E_USER_ERROR` given to
 * `trigger_error()`, a byte out of range given to `chr()` and a string not one byte long to `ord()`, an integer given to
 * a `ctype_*()` function, the directory handle left out or null for `readdir()`, `rewinddir()` and `closedir()`, and
 * a length other than 0 given to `openssl_pkey_derive()`, which truncates an ECDH secret and fails for DH; a length
 * that is not a literal is reported too, being passed only to truncate.
 * The rule reports it and rewrites nothing: what takes its place, an exception, the right byte, a string, the
 * handle or the secret cut to length, is for the author to write.
 * @internal
 */
final class DeprecatedArguments
{
	private const Ctype = ['text', 0, 'An integer given to a `ctype_*()` function is deprecated since PHP 8.1.'];

	/** function => the name of the parameter, its position, and the message */
	private const Arguments = [
		'trigger_error' => ['error_level', 1, 'Passing `E_USER_ERROR` to `trigger_error()` is deprecated since PHP 8.4 in favor of throwing an exception.'],
		'chr' => ['codepoint', 0, 'A value of `chr()` outside 0 to 255 is deprecated since PHP 8.5.'],
		'ord' => ['character', 0, 'A string of `ord()` not one byte long is deprecated since PHP 8.5.'],
		'readdir' => ['dir_handle', 0, 'A missing or null handle of `readdir()` is deprecated since PHP 8.5.'],
		'rewinddir' => ['dir_handle', 0, 'A missing or null handle of `rewinddir()` is deprecated since PHP 8.5.'],
		'closedir' => ['dir_handle', 0, 'A missing or null handle of `closedir()` is deprecated since PHP 8.5.'],
		'ctype_alnum' => self::Ctype,
		'ctype_alpha' => self::Ctype,
		'ctype_cntrl' => self::Ctype,
		'ctype_digit' => self::Ctype,
		'ctype_graph' => self::Ctype,
		'ctype_lower' => self::Ctype,
		'ctype_print' => self::Ctype,
		'ctype_punct' => self::Ctype,
		'ctype_space' => self::Ctype,
		'ctype_upper' => self::Ctype,
		'ctype_xdigit' => self::Ctype,
		'openssl_pkey_derive' => ['key_length', 2, 'The `key_length` argument of `openssl_pkey_derive()` is deprecated since PHP 8.5.'],
	];


	/**
	 * Reports the argument of the call PHP deprecated, unless the function is among those withdrawn.
	 * @param  array<string, true>  $except  the names withdrawn, in lower case
	 */
	public static function check(FunctionCallNode $call, RuleContext $context, array $except): void
	{
		if (
			($function = GlobalCalls::findFunction($call, self::Arguments, $context)) === null
			|| isset($except[$function])
			|| $call->arguments->isPartialApplication()
		) {
			return;
		}

		[$parameter, $position, $message] = self::Arguments[$function];
		$value = $call->arguments->findArgument($parameter, $position)?->value;
		if (self::isDeprecated($function, $value)) {
			$context->report($value ?? $call, $message, decision: 'upgrading.php.deprecatedCall', fixable: false);
		}
	}


	/** Whether the value written out, or left out, is the one PHP deprecated for the function. */
	private static function isDeprecated(string $function, ?ExpressionNode $value): bool
	{
		$literal = $value?->hasValue() ? $value->toValue() : null;
		return match ($function) {
			'trigger_error' => ($value instanceof ConstantFetchNode && ltrim($value->name->text, '\\') === 'E_USER_ERROR')
				|| ($value instanceof IntegerNode && $literal === E_USER_ERROR),
			'chr' => is_int($literal) && ($literal < 0 || $literal > 255),
			'ord' => $value instanceof StringNode && strlen($value->toValue()) !== 1,
			'readdir', 'rewinddir', 'closedir' => $value === null || $value instanceof NullNode,
			'openssl_pkey_derive' => $value !== null && $literal !== 0,
			default => is_int($literal),
		};
	}
}
