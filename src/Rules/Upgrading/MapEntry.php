<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;


/**
 * The entry of a map of members an access was found under: its key, what the rule keeps of its value, and how the
 * arguments of the call bind to the key where the call was asked about.
 * @template T
 */
final readonly class MapEntry
{
	public function __construct(
		public MemberPattern $pattern,
		/** @var T */
		public mixed $value,
		/** null where the arguments were not asked about */
		public ?ArgumentBindings $bindings = null,
	) {
	}
}
