<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * One answer to what `dresscode init` asks, and what the configuration does with it: decisions it writes, or a standard
 * it measures against instead.
 * @internal
 */
final readonly class Answer
{
	public function __construct(
		/** what the user types to choose it */
		public string $key,
		/** what it does, with what it costs: "no blank line everywhere, rewrites 35 files" */
		public string $label,
		/** @var array<string, mixed>  path => the value it writes, as the configuration writes it */
		public array $values = [],
		/** what the values it writes rest on, written beside them; "your answer" where nothing more is to say */
		public string $comment = 'your answer',
		/** @var ?list<string>  the standard it measures against instead */
		public ?array $presets = null,
	) {
	}
}
