<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;


/**
 * The rules a configuration of another formatter turns on, as `Translator` takes them, and what of it is not carried
 * over.
 * @internal
 */
final readonly class ForeignRules
{
	public function __construct(
		/** @var array<string, bool|array<string, mixed>>  foreign rule => on, off or its options */
		public array $rules,
		/** @var list<string>  the presets of DressCode the preset of the configuration is */
		public array $presets = [],
		/** @var list<string>  what of the configuration is not carried over */
		public array $warnings = [],
		/** the indent the fixers of whitespace write */
		public string $indent = '    ',
		/** the line ending the fixers of whitespace write */
		public string $lineEnding = "\n",
	) {
	}
}
