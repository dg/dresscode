<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use function in_array;


/**
 * What a deprecation says: its text, and the replacement it names when it names one in a shape a tool
 * can read, `use Order::StatusPaid`, `use recalculate()`, `use $items`.
 */
final readonly class Deprecation
{
	public function __construct(
		public string $description,
		/** the class of the replacement as the description writes it; null when it writes none, the replacement then being a member of the same class */
		public ?string $replacementClass = null,
		/** name of the replacement member, without the parentheses of a method */
		public ?string $replacementName = null,
		/** the replacement is written as a call */
		public bool $replacementIsCall = false,
	) {
	}


	public static function fromDescription(string $description): self
	{
		$code = self::findReplacementCode($description);
		return $code !== null && preg_match('~^(?:([\w\\\]+)::)?(\$?\w+)(\(\))?$~D', $code, $m)
			? new self(
				$description,
				$m[1] === '' || in_array(strtolower($m[1]), ['self', 'static'], true) ? null : ltrim($m[1], '\\'),
				$m[2],
				isset($m[3]),
			)
			: new self($description);
	}


	/**
	 * The code a description names as the replacement, without the words around it: `use Order::StatusPaid instead.`,
	 * `since acme/shop 2.4, use "setPaid()" instead`, `use const STATUS_PAID instead`, `use the {@see Foo} class instead`
	 * and `use ->send() instead` name `Order::StatusPaid`, `setPaid()`, `STATUS_PAID`, `Foo` and `send()`. Null for
	 * a description that names none, such as `use the #[Paid] attribute instead`.
	 */
	public static function findReplacementCode(string $description): ?string
	{
		return preg_match(<<<'X'
			~^
				(?:since\s+\S+\s+v?\d+(?:\.\d+)*[,;]\s*)?
				(?:to\s+be\s+removed\s+in\s+v?\d+(?:\.\d+)*[,;]\s*)?
				use\s+(?:the\s+)?(?:(?:public\s+|protected\s+)?const\s+)?
				(?| "([^"\s]+)" | `([^`\s]+)` | \{@(?:see|link)\s+([^}\s]+)\s*} | ([^\s"`{]+?) )
				(?:\s+(?:class|interface))?
				(?:\s+instead)?\.?
			$~ixD
			X, trim($description), $m)
			? (string) preg_replace('~^->~', '', $m[1])
			: null;
	}
}
