<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * A profile for a part of the tree: a file the patterns match gets it on top of the configuration, and of the overrides
 * it matches the later one has the last word. A part of a project with a convention of its own needs a profile of its
 * own, not a rule turned off everywhere.
 */
final readonly class Override extends Profile
{
	/**
	 * @param list<string> $presets
	 * @param list<string|Group> $groups
	 * @param array<string, bool|string|int|array<string, mixed>|\Closure(): Rule> $rules
	 * @param array{functions?: list<string>, constants?: list<string>} $namespaces
	 * @param list<string> $fixRisky
	 * @param list<string> $warnings
	 */
	public function __construct(
		/** @var list<string>  patterns, relative to the root */
		public array $paths,
		array $presets = [],
		array $groups = [],
		array $rules = [],
		int|string|null $indent = null,
		?string $eol = null,
		int|false|null $lineLength = null,
		?string $php = null,
		array $namespaces = [],
		?string $nameResolution = null,
		array $fixRisky = [],
		array $warnings = [],
	) {
		if ($paths === []) {
			throw new \InvalidArgumentException('An override needs the paths it applies to.');
		}

		parent::__construct($presets, $groups, $rules, $indent, $eol, $lineLength, $php, $namespaces, $nameResolution, $fixRisky, $warnings);
	}
}
