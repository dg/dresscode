<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;


/**
 * What the migration of the suppression comments did to one file.
 * @internal
 */
final readonly class SuppressionRewrite
{
	public function __construct(
		/** the directives and tags rewritten */
		public int $count = 0,
		/** @var array<string, list<int>>  a name nothing covers, kept as written => the lines it stands on */
		public array $unknownNames = [],
		/** @var list<int>  the lines of an ignore on a line of its own, whose scope grows from the next line to the whole next statement */
		public array $widened = [],
	) {
	}
}
