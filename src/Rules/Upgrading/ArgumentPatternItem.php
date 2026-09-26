<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;


/**
 * One item of a pattern of arguments: a placeholder `$name`, one of a type `array $name`, a literal, an array literal
 * with the keys it has to have, `['mode' => $mode, ...$options]`, any of them under the name the argument is passed by
 * (`fallback: $f`), or the rest of the arguments, `...$args` under a placeholder and `...` as they are.
 */
final readonly class ArgumentPatternItem
{
	public function __construct(
		/** without the dollar; null for a literal and for `...` */
		public ?string $placeholder = null,
		/** @var ?array{mixed}  the value of a literal, in a list so that null can be one */
		public ?array $literal = null,
		/** the name the argument has to be passed by; null for a positional one */
		public ?string $parameterName = null,
		/** stands for the rest of the arguments */
		public bool $variadic = false,
		/** the type the argument of the placeholder has for certain, as PHP writes a type, `list` besides; null for any */
		public ?string $type = null,
		/** @var ?array<string, string>  the keys an array literal has to have => the placeholders of their values; null for no array */
		public ?array $keys = null,
		/** the placeholder of the other items of such an array, without the dollar; null where it may have none */
		public ?string $otherItems = null,
	) {
	}
}
